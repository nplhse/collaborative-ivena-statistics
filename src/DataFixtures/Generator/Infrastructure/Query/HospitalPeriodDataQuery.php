<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Infrastructure\Query;

use App\DataFixtures\Generator\Application\Dataset\DatasetPeriod;
use App\DataFixtures\Generator\Application\Dataset\HospitalPeriodData;
use App\DataFixtures\Generator\Application\SyntheticImportMarker;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Which imports of a hospital touch a period, and of which kind (#877, ADR 009).
 *
 * Generated month imports are recognised by their marker path (SyntheticImportMarker) and scenario imports by
 * `synthetic/scenario/…/<hospital>/…`; every other import counts as real. A real import touches a month when it
 * has allocations or closures in it or its export period overlaps it.
 */
final readonly class HospitalPeriodDataQuery
{
    private const string SCENARIO_PATTERN = '#^synthetic/scenario/[^/]+/seed-\d+/[0-9a-f-]{36}/(?<day>\d{4}-\d{2}-\d{2})#';

    public function __construct(private Connection $connection)
    {
    }

    public function find(int $hospitalId, DatasetPeriod $period): HospitalPeriodData
    {
        $from = $period->firstDay()->format('Y-m-d H:i:s');
        $to = $period->end()->format('Y-m-d H:i:s');

        /** @var list<array{id: int|string, file_path: ?string, row_count: int|string|null, export_starts_at: ?string, export_ends_at: ?string}> $imports */
        $imports = $this->connection->fetchAllAssociative(
            'SELECT id, file_path, row_count, export_starts_at, export_ends_at FROM import WHERE hospital_id = :hospital ORDER BY id',
            ['hospital' => $hospitalId],
        );
        if ([] === $imports) {
            return new HospitalPeriodData([], [], []);
        }
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $imports);

        $monthsByImport = $this->monthsWithRows($ids, $from, $to);
        $months = $period->months();

        $real = [];
        $generated = [];
        $scenarios = [];
        foreach ($imports as $row) {
            $id = (int) $row['id'];
            $path = (string) $row['file_path'];
            $rowMonths = array_keys($monthsByImport[$id] ?? []);

            $marker = SyntheticImportMarker::fromPath($path);
            if ($marker instanceof SyntheticImportMarker) {
                if (\in_array($marker->month, $months, true)) {
                    $generated[$marker->month][] = ['id' => $id, 'type' => $marker->type, 'seed' => $marker->seed, 'row_count' => (int) $row['row_count'], 'rows' => 0, 'projected' => false];
                }
                continue;
            }

            if (str_starts_with($path, 'synthetic/scenario/')) {
                $start = 1 === preg_match(self::SCENARIO_PATTERN, $path, $match) ? substr($match['day'], 0, 7) : null;
                $scenarioMonths = array_values(array_unique([...$rowMonths, ...(null !== $start && \in_array($start, $months, true) ? [$start] : [])]));
                if ([] !== $scenarioMonths) {
                    sort($scenarioMonths);
                    $scenarios[] = ['id' => $id, 'months' => $scenarioMonths];
                }
                continue;
            }

            foreach (array_unique([...$rowMonths, ...$this->exportMonths($row['export_starts_at'], $row['export_ends_at'], $months)]) as $month) {
                $real[$month][] = $id;
            }
        }

        return new HospitalPeriodData($real, $this->withRowCounts($generated), $scenarios);
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, array<string, true>> import id => months (Y-m) with allocations or closures
     */
    private function monthsWithRows(array $ids, string $from, string $to): array
    {
        /** @var list<array{import_id: int|string, month: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            "SELECT DISTINCT import_id, to_char(created_at, 'YYYY-MM') AS month FROM allocation
             WHERE import_id IN (:ids) AND created_at >= :from AND created_at < :to
             UNION
             SELECT DISTINCT import_id, to_char(starts_at, 'YYYY-MM') AS month FROM closure_interval
             WHERE import_id IN (:ids) AND starts_at >= :from AND starts_at < :to",
            ['ids' => $ids, 'from' => $from, 'to' => $to],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $months = [];
        foreach ($rows as $row) {
            $months[(int) $row['import_id']][$row['month']] = true;
        }

        return $months;
    }

    /**
     * @param list<string> $months
     *
     * @return list<string>
     */
    private function exportMonths(?string $startsAt, ?string $endsAt, array $months): array
    {
        if (null === $startsAt || null === $endsAt) {
            return [];
        }
        $start = substr($startsAt, 0, 7);
        $end = substr($endsAt, 0, 7);

        return array_values(array_filter($months, static fn (string $month): bool => $month >= $start && $month <= $end));
    }

    /**
     * Adds the rows each generated import holds and whether its allocations are projected.
     *
     * @param array<string, list<array{id: int, type: string, seed: int, row_count: int, rows: int, projected: bool}>> $generated
     *
     * @return array<string, list<array{id: int, type: string, seed: int, row_count: int, rows: int, projected: bool}>>
     */
    private function withRowCounts(array $generated): array
    {
        $ids = [];
        foreach ($generated as $imports) {
            array_push($ids, ...array_column($imports, 'id'));
        }
        if ([] === $ids) {
            return [];
        }

        $params = ['ids' => $ids];
        $types = ['ids' => ArrayParameterType::INTEGER];
        $allocations = $this->connection->fetchAllKeyValue('SELECT import_id, COUNT(*) FROM allocation WHERE import_id IN (:ids) GROUP BY import_id', $params, $types);
        $closures = $this->connection->fetchAllKeyValue('SELECT import_id, COUNT(*) FROM closure_interval WHERE import_id IN (:ids) GROUP BY import_id', $params, $types);
        /** @var list<int|string> $projected */
        $projected = $this->connection->fetchFirstColumn('SELECT DISTINCT import_id FROM allocation_stats_projection WHERE import_id IN (:ids)', $params, $types);
        $projected = array_map(intval(...), $projected);

        return array_map(static fn (array $imports): array => array_map(static fn (array $import): array => [
            'id' => $import['id'],
            'type' => $import['type'],
            'seed' => $import['seed'],
            'row_count' => $import['row_count'],
            'rows' => (int) (SyntheticImportMarker::ALLOCATIONS === $import['type'] ? ($allocations[$import['id']] ?? 0) : ($closures[$import['id']] ?? 0)),
            'projected' => \in_array($import['id'], $projected, true),
        ], $imports), $generated);
    }
}
