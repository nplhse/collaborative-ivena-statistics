<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Diagnostics;

use App\Statistics\Application\Contract\ClosureVolumeSnapshotInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Stores snapshots as plain tables `closure_volume_snapshot_<name>` next to closure_volume_hour.
 * Keys and value columns follow the current grain (event × scope × population × metric × bucket).
 * A legacy snapshot (urgency × stratum, before ADR 015) maps `base` rows of urgency 1–3 to the
 * urgency metrics and is compared with department rows the event closed.
 */
final readonly class ClosureVolumeSnapshotStore implements ClosureVolumeSnapshotInterface
{
    private const string PREFIX = 'closure_volume_snapshot_';

    /** @var list<string> */
    private const array KEYS = ['event_id', 'scope', 'speciality_id', 'department_id', 'metric_kind', 'bucket_start'];

    /** @var list<string> */
    private const array NUMERIC = [
        'observed_area', 'expected_area', 'observed_hospital', 'expected_hospital',
        'evaluable_seconds', 'bucket_seconds', 'reference_slot_count', 'reference_assignment_count',
    ];

    /** @var list<string> */
    private const array EXACT = ['in_closure', 'reference_mode', 'influenced', 'quality', 'bucket_end', 'reference_cutoff'];

    /** @var list<string> columns that only exist since ADR 015 */
    private const array NUMERIC_CURRENT = ['blocked_slot_count', 'closed_department_count'];

    /** @var list<string> */
    private const array EXACT_CURRENT = ['closure_relevance', 'provisional', 'provisional_reason'];

    private const string LEGACY_METRIC = "CASE urgency_code WHEN 1 THEN 'emergency' WHEN 2 THEN 'inpatient' WHEN 3 THEN 'outpatient' END";

    public function __construct(private Connection $connection)
    {
    }

    #[\Override]
    public function save(string $name, ?int $hospitalId = null): int
    {
        $table = $this->table($name);
        $this->connection->executeStatement('DROP TABLE IF EXISTS '.$table);
        $where = null === $hospitalId ? '' : ' WHERE hospital_id = '.$hospitalId;
        $this->connection->executeStatement('CREATE TABLE '.$table.' AS SELECT * FROM closure_volume_hour'.$where);

        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    #[\Override]
    public function compare(string $name, ?int $hospitalId = null, float $tolerance = 0.0001): array
    {
        $table = $this->table($name);
        $this->assertExists($table);
        $legacy = $this->isLegacy($table);
        $filter = null === $hospitalId ? 'TRUE' : 'hospital_id = '.$hospitalId;
        $join = implode(' AND ', array_map(static fn (string $key): string => "s.{$key} = c.{$key}", self::KEYS));
        $numeric = $legacy ? self::NUMERIC : [...self::NUMERIC, ...self::NUMERIC_CURRENT];
        $exact = $legacy ? self::EXACT : [...self::EXACT, ...self::EXACT_CURRENT];
        $diffs = [];
        foreach ($numeric as $column) {
            $diffs[$column] = "((s.{$column} IS NULL) <> (c.{$column} IS NULL) OR abs(s.{$column} - c.{$column}) > CAST(:tolerance AS numeric))";
        }
        foreach ($exact as $column) {
            $diffs[$column] = "(s.{$column} IS DISTINCT FROM c.{$column})";
        }
        $anyDiff = implode(' OR ', $diffs);
        $columnCounts = implode(",\n       ", array_map(
            static fn (string $column, string $expression): string => "COUNT(*) FILTER (WHERE s.event_id IS NOT NULL AND c.event_id IS NOT NULL AND {$expression}) AS \"{$column}\"",
            array_keys($diffs),
            $diffs,
        ));
        if ($legacy) {
            $columns = implode(', ', ['event_id', 'scope', 'hospital_id', 'speciality_id', 'department_id', 'bucket_start', ...self::NUMERIC, ...self::EXACT]);
            $metric = self::LEGACY_METRIC;
            $snapshot = "SELECT {$columns}, {$metric} AS metric_kind FROM {$table} WHERE {$filter} AND stratum = 'base' AND scope = 'department' AND urgency_code IN (1, 2, 3)";
            $current = "SELECT * FROM closure_volume_hour WHERE {$filter} AND scope = 'department' AND metric_kind IN ('emergency', 'inpatient', 'outpatient') AND closure_relevance = 'closed'";
        } else {
            $snapshot = "SELECT * FROM {$table} WHERE {$filter}";
            $current = "SELECT * FROM closure_volume_hour WHERE {$filter}";
        }
        $base = <<<SQL
FROM ({$snapshot}) s
FULL OUTER JOIN ({$current}) c ON {$join}
SQL;
        /** @var array<string, int|string> $row */
        $row = $this->connection->fetchAssociative(
            <<<SQL
SELECT COUNT(s.event_id) AS snapshot_rows,
       COUNT(c.event_id) AS current_rows,
       COUNT(*) FILTER (WHERE c.event_id IS NULL) AS only_in_snapshot,
       COUNT(*) FILTER (WHERE s.event_id IS NULL) AS only_in_current,
       COUNT(*) FILTER (WHERE s.event_id IS NOT NULL AND c.event_id IS NOT NULL AND ({$anyDiff})) AS changed_rows,
       {$columnCounts}
{$base}
SQL,
            ['tolerance' => (string) $tolerance],
            ['tolerance' => ParameterType::STRING],
        );
        $keys = implode(', ', array_map(static fn (string $key): string => "coalesce(s.{$key}, c.{$key}) AS {$key}", self::KEYS));
        /** @var list<array<string, scalar|null>> $largest */
        $largest = $this->connection->fetchAllAssociative(
            <<<SQL
SELECT {$keys},
       s.expected_area AS snapshot_expected, c.expected_area AS current_expected,
       s.observed_area AS snapshot_observed, c.observed_area AS current_observed,
       s.reference_mode AS snapshot_mode, c.reference_mode AS current_mode
{$base}
WHERE s.event_id IS NULL OR c.event_id IS NULL OR ({$anyDiff})
ORDER BY abs(coalesce(s.expected_area, 0) - coalesce(c.expected_area, 0)) DESC NULLS LAST
LIMIT 10
SQL,
            ['tolerance' => (string) $tolerance],
            ['tolerance' => ParameterType::STRING],
        );
        $changedColumns = [];
        foreach (array_keys($diffs) as $column) {
            $changedColumns[$column] = (int) ($row[$column] ?? 0);
        }

        return [
            'legacy' => $legacy,
            'snapshot_rows' => (int) $row['snapshot_rows'],
            'current_rows' => (int) $row['current_rows'],
            'only_in_snapshot' => (int) $row['only_in_snapshot'],
            'only_in_current' => (int) $row['only_in_current'],
            'changed_rows' => (int) $row['changed_rows'],
            'changed_columns' => $changedColumns,
            'largest_differences' => $largest,
        ];
    }

    #[\Override]
    public function drop(string $name): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS '.$this->table($name));
    }

    #[\Override]
    public function list(): array
    {
        /** @var list<string> $tables */
        $tables = $this->connection->fetchFirstColumn(
            "SELECT tablename FROM pg_tables WHERE schemaname = current_schema() AND tablename LIKE 'closure\\_volume\\_snapshot\\_%' ORDER BY tablename",
        );
        $snapshots = [];
        foreach ($tables as $table) {
            $snapshots[] = [
                'name' => substr($table, \strlen(self::PREFIX)),
                'rows' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$this->connection->quoteSingleIdentifier($table)),
            ];
        }

        return $snapshots;
    }

    private function table(string $name): string
    {
        if (1 !== preg_match('/^[a-z0-9_]{1,40}$/', $name)) {
            throw new \InvalidArgumentException('Snapshot names use 1–40 characters a–z, 0–9 and underscore.');
        }

        return self::PREFIX.$name;
    }

    private function isLegacy(string $table): bool
    {
        return false !== $this->connection->fetchOne(
            "SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = :table AND column_name = 'stratum'",
            ['table' => $table],
        );
    }

    private function assertExists(string $table): void
    {
        $exists = $this->connection->fetchOne(
            'SELECT 1 FROM pg_tables WHERE schemaname = current_schema() AND tablename = :table',
            ['table' => $table],
        );
        if (false === $exists) {
            throw new \InvalidArgumentException(sprintf('Snapshot table %s does not exist.', $table));
        }
    }
}
