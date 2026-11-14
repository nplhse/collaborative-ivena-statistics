<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Query;

use App\Encounter\Application\Contract\PlausibilityTableReaderInterface;
use App\Encounter\Application\Plausibility\PlausibilityTable;
use App\Encounter\Domain\Enum\PlausibilityCellSource;
use Doctrine\DBAL\Connection;

/**
 * Reads the cells and memberships of a plausibility snapshot (ADR 033). Indicator cells are reserved and not read.
 * Positives and negatives are summed to observations for the lookup rule only.
 *
 * @psalm-suppress UnusedClass Wired as the only implementation of PlausibilityTableReaderInterface.
 */
final readonly class PlausibilityTableQuery implements PlausibilityTableReaderInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function activeSnapshotId(): ?int
    {
        $id = $this->connection->fetchOne("SELECT id FROM encounter_plausibility_snapshot WHERE status = 'active'");

        return false === $id ? null : (int) $id;
    }

    #[\Override]
    public function table(int $snapshotId, int $minObservations): PlausibilityTable
    {
        /** @var array<int, string> $areas */
        $areas = [];
        foreach ($this->connection->fetchAllAssociative('SELECT pzc, area_code FROM encounter_plausibility_snapshot_code WHERE snapshot_id = :id', ['id' => $snapshotId]) as $row) {
            $areas[(int) $row['pzc']] = (string) $row['area_code'];
        }

        $exact = [];
        $areaCells = [];
        $rows = $this->connection->fetchAllAssociative(
            'SELECT pzc, area_code, diagram_code, points, source, positives + negatives AS observations FROM encounter_plausibility_cell WHERE snapshot_id = :id AND indicator_code IS NULL',
            ['id' => $snapshotId],
        );
        foreach ($rows as $row) {
            $source = PlausibilityCellSource::from((string) $row['source']);
            $diagram = (string) $row['diagram_code'];
            if (null !== $row['pzc']) {
                $exact[(int) $row['pzc']][$diagram] = ['points' => (int) $row['points'], 'observations' => (int) $row['observations'], 'source' => $source];
            } else {
                $areaCells[(string) $row['area_code']][$diagram] = ['points' => (int) $row['points'], 'source' => $source];
            }
        }

        return new PlausibilityTable($snapshotId, $minObservations, $areas, $exact, $areaCells);
    }
}
