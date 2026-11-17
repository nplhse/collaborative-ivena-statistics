<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Infrastructure\Query;

use Cois\Plugin\EdVisit\Application\Contract\CurationStateReaderInterface;
use Cois\Plugin\EdVisit\Application\Curation\CurationState;
use Cois\Plugin\EdVisit\Domain\Enum\MtsCatalogKind;
use Cois\Plugin\EdVisit\Domain\Enum\MtsCatalogStatus;
use Doctrine\DBAL\Connection;

/**
 * Reads the MTS catalog tables (ADR 032) and the problem areas with their PZC codes and rules (ADR 033), ordered by
 * code. Snapshots, cells and counts are never read.
 *
 * @psalm-suppress UnusedClass Wired as the only implementation of CurationStateReaderInterface.
 */
final readonly class CurationStateQuery implements CurationStateReaderInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function state(): CurationState
    {
        $entries = [];
        foreach ([MtsCatalogKind::Diagram->value => 'encounter_mts_diagram', MtsCatalogKind::Indicator->value => 'encounter_mts_indicator'] as $kind => $table) {
            $entries[$kind] = [];
            /** @var list<array{code: string, german_name: string, english_name: ?string, source: string, status: string}> $rows */
            $rows = $this->connection->fetchAllAssociative(\sprintf('SELECT code, german_name, english_name, source, status FROM %s ORDER BY code', $table));
            foreach ($rows as $row) {
                $entries[$kind][$row['code']] = [
                    'german_name' => $row['german_name'],
                    'english_name' => $row['english_name'],
                    'source' => $row['source'],
                    'status' => MtsCatalogStatus::from($row['status']),
                ];
            }
        }

        $aliases = [MtsCatalogKind::Diagram->value => [], MtsCatalogKind::Indicator->value => []];
        /** @var list<array{kind: string, code: string, label: string, target_code: string}> $rows */
        $rows = $this->connection->fetchAllAssociative('SELECT kind, code, label, target_code FROM encounter_mts_alias ORDER BY kind, code');
        foreach ($rows as $row) {
            $aliases[$row['kind']][$row['code']] = ['label' => $row['label'], 'target_code' => $row['target_code']];
        }

        $assignments = [];
        /** @var list<array{diagram: string, indicator: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT d.code AS diagram, i.code AS indicator FROM encounter_mts_diagram_indicator a JOIN encounter_mts_diagram d ON d.id = a.diagram_id JOIN encounter_mts_indicator i ON i.id = a.indicator_id ORDER BY d.code, i.code',
        );
        foreach ($rows as $row) {
            $assignments[$row['diagram']][] = $row['indicator'];
        }

        return new CurationState($entries, $aliases, $assignments, $this->areas());
    }

    /**
     * @return array<string, array{german_name: string, english_name: ?string, pzc: list<int>, rules: array<string, int>}>
     */
    private function areas(): array
    {
        $areas = [];
        /** @var list<array{id: int|string, code: string, german_name: string, english_name: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative('SELECT id, code, german_name, english_name FROM encounter_plausibility_area ORDER BY code');
        $codeById = [];
        foreach ($rows as $row) {
            $codeById[(int) $row['id']] = $row['code'];
            $areas[$row['code']] = ['german_name' => $row['german_name'], 'english_name' => $row['english_name'], 'pzc' => [], 'rules' => []];
        }

        /** @var list<array{area_id: int|string, pzc: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative('SELECT area_id, pzc FROM encounter_plausibility_area_code ORDER BY pzc');
        foreach ($rows as $row) {
            $areas[$codeById[(int) $row['area_id']]]['pzc'][] = (int) $row['pzc'];
        }

        /** @var list<array{area_id: int|string, diagram: string, points: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT r.area_id, d.code AS diagram, r.points FROM encounter_plausibility_area_diagram r JOIN encounter_mts_diagram d ON d.id = r.diagram_id ORDER BY d.code',
        );
        foreach ($rows as $row) {
            $areas[$codeById[(int) $row['area_id']]]['rules'][$row['diagram']] = (int) $row['points'];
        }

        return $areas;
    }
}
