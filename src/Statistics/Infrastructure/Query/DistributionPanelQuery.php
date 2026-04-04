<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

use App\Statistics\Application\Filter\FilterState;
use App\Statistics\Application\Panel\Distribution\AgeCohortBucketExpression;
use App\Statistics\Application\Panel\Distribution\DimensionKind;
use App\Statistics\Application\Panel\Distribution\DistributionNumericMetric;
use App\Statistics\Application\Panel\PanelDefinition;
use Doctrine\DBAL\Connection;

final readonly class DistributionPanelQuery
{
    public function __construct(
        private Connection $connection,
        private SqlFilterBuilder $sqlFilterBuilder,
    ) {
    }

    /**
     * @return list<array{dimension_key: int, group_key: int|null, value: int, distinct_hospitals: int}>
     */
    public function fetchDistribution(
        PanelDefinition $panel,
        FilterState $filterState,
        ?string $groupByField = null,
    ): array {
        if (!\is_string($groupByField) || '' === $groupByField) {
            $groupByField = null;
        }

        $dimensionSelect = match ($panel->dimensionKind) {
            DimensionKind::AgeCohort => '('.AgeCohortBucketExpression::sql('age').')',
            DimensionKind::Column => $panel->dimensionField,
        };

        $filter = $this->sqlFilterBuilder->buildWhere($filterState, $panel);
        $sql = 'SELECT '.$dimensionSelect.' AS dimension_key, '
            .($groupByField ?? 'NULL').' AS group_key, COUNT(*) AS value, '
            .'COUNT(DISTINCT hospital_id) AS distinct_hospitals '
            .'FROM allocation_stats_projection'
            .$filter['where']
            .' GROUP BY '.$dimensionSelect.(null === $groupByField ? '' : ', '.$groupByField)
            .' ORDER BY '.$dimensionSelect.(null === $groupByField ? '' : ', '.$groupByField);

        /** @var list<array{dimension_key: mixed, group_key: mixed, value: mixed, distinct_hospitals: mixed}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $filter['params'], $filter['types']);

        $out = [];
        foreach ($rows as $row) {
            $groupRaw = $row['group_key'];
            $out[] = [
                'dimension_key' => (int) $row['dimension_key'],
                'group_key' => null === $groupRaw || '' === $groupRaw ? null : (int) $groupRaw,
                'value' => (int) $row['value'],
                'distinct_hospitals' => (int) $row['distinct_hospitals'],
            ];
        }

        return $out;
    }

    /**
     * Overall case count and participating hospitals for the current filter (no dimension/group breakdown).
     *
     * @return array{allocations: int, distinct_hospitals: int}|null null when no rows
     */
    public function fetchOverallHospitalParticipation(
        PanelDefinition $panel,
        FilterState $filterState,
    ): ?array {
        $filter = $this->sqlFilterBuilder->buildWhere($filterState, $panel);
        $sql = 'SELECT COUNT(*) AS allocations, COUNT(DISTINCT hospital_id) AS distinct_hospitals '
            .'FROM allocation_stats_projection'
            .$filter['where'];

        $row = $this->connection->fetchAssociative($sql, $filter['params'], $filter['types']);

        if (!\is_array($row)) {
            return null;
        }

        $allocations = (int) $row['allocations'];
        if ($allocations <= 0) {
            return null;
        }

        return [
            'allocations' => $allocations,
            'distinct_hospitals' => max(0, (int) $row['distinct_hospitals']),
        ];
    }

    /**
     * Aggregates on a numeric projection column (non-null only), same GROUP BY as fetchDistribution.
     *
     * @return list<array{
     *     dimension_key: int,
     *     group_key: int|null,
     *     n: int,
     *     mean: float,
     *     min: int,
     *     q1: float,
     *     median: float,
     *     q3: float,
     *     max: int
     * }>
     */
    public function fetchNumericDistributionStats(
        PanelDefinition $panel,
        FilterState $filterState,
        DistributionNumericMetric $metric,
        ?string $groupByField = null,
    ): array {
        if (!\is_string($groupByField) || '' === $groupByField) {
            $groupByField = null;
        }

        $column = $metric->sqlColumn();
        $dimensionSelect = match ($panel->dimensionKind) {
            DimensionKind::AgeCohort => '('.AgeCohortBucketExpression::sql('age').')',
            DimensionKind::Column => $panel->dimensionField,
        };

        $filter = $this->sqlFilterBuilder->buildWhere($filterState, $panel);
        $where = $this->appendColumnNotNull($filter['where'], $column);

        $sql = 'SELECT '.$dimensionSelect.' AS dimension_key, '
            .($groupByField ?? 'NULL').' AS group_key, '
            .'COUNT(*) AS n, '
            .'AVG('.$column.')::double precision AS mean_val, '
            .'MIN('.$column.') AS min_val, '
            .'percentile_cont(0.25) WITHIN GROUP (ORDER BY '.$column.') AS q1_val, '
            .'percentile_cont(0.5) WITHIN GROUP (ORDER BY '.$column.') AS median_val, '
            .'percentile_cont(0.75) WITHIN GROUP (ORDER BY '.$column.') AS q3_val, '
            .'MAX('.$column.') AS max_val '
            .'FROM allocation_stats_projection'
            .$where
            .' GROUP BY '.$dimensionSelect.(null === $groupByField ? '' : ', '.$groupByField)
            .' ORDER BY '.$dimensionSelect.(null === $groupByField ? '' : ', '.$groupByField);

        $rows = $this->connection->fetchAllAssociative($sql, $filter['params'], $filter['types']);

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->mapNumericStatsRow($row);
        }

        return $out;
    }

    /**
     * @return array{
     *     n: int,
     *     mean: float,
     *     min: int,
     *     q1: float,
     *     median: float,
     *     q3: float,
     *     max: int
     * }|null
     */
    public function fetchOverallNumericStats(
        PanelDefinition $panel,
        FilterState $filterState,
        DistributionNumericMetric $metric,
    ): ?array {
        $column = $metric->sqlColumn();
        $filter = $this->sqlFilterBuilder->buildWhere($filterState, $panel);
        $where = $this->appendColumnNotNull($filter['where'], $column);

        $sql = 'SELECT '
            .'COUNT(*) AS n, '
            .'AVG('.$column.')::double precision AS mean_val, '
            .'MIN('.$column.') AS min_val, '
            .'percentile_cont(0.25) WITHIN GROUP (ORDER BY '.$column.') AS q1_val, '
            .'percentile_cont(0.5) WITHIN GROUP (ORDER BY '.$column.') AS median_val, '
            .'percentile_cont(0.75) WITHIN GROUP (ORDER BY '.$column.') AS q3_val, '
            .'MAX('.$column.') AS max_val '
            .'FROM allocation_stats_projection'
            .$where;

        $row = $this->connection->fetchAssociative($sql, $filter['params'], $filter['types']);

        if (!\is_array($row)) {
            return null;
        }

        $n = (int) $row['n'];
        if ($n <= 0) {
            return null;
        }

        return [
            'n' => $n,
            'mean' => (float) $row['mean_val'],
            'min' => (int) $row['min_val'],
            'q1' => (float) $row['q1_val'],
            'median' => (float) $row['median_val'],
            'q3' => (float) $row['q3_val'],
            'max' => (int) $row['max_val'],
        ];
    }

    private function appendColumnNotNull(string $where, string $column): string
    {
        if ('' === $where) {
            return ' WHERE '.$column.' IS NOT NULL';
        }

        return $where.' AND '.$column.' IS NOT NULL';
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{
     *     dimension_key: int,
     *     group_key: int|null,
     *     n: int,
     *     mean: float,
     *     min: int,
     *     q1: float,
     *     median: float,
     *     q3: float,
     *     max: int
     * }
     */
    private function mapNumericStatsRow(array $row): array
    {
        $groupRaw = $row['group_key'];

        return [
            'dimension_key' => (int) $row['dimension_key'],
            'group_key' => null === $groupRaw || '' === $groupRaw ? null : (int) $groupRaw,
            'n' => (int) $row['n'],
            'mean' => (float) $row['mean_val'],
            'min' => (int) $row['min_val'],
            'q1' => (float) $row['q1_val'],
            'median' => (float) $row['median_val'],
            'q3' => (float) $row['q3_val'],
            'max' => (int) $row['max_val'],
        ];
    }
}
