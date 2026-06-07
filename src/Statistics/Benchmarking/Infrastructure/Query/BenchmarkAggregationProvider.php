<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\Mapping\AllocationStatsDayTimeBucketProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsUrgencyProjectionCode;
use App\Statistics\Application\Mapping\StatisticsAgeGroupBucketSql;
use App\Statistics\Application\Mapping\StatisticsTransportTimeBucketSql;
use App\Statistics\Benchmarking\Application\Contract\BenchmarkAggregationProviderInterface;
use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkAggregationResult;
use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkDistributionRow;
use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkSideCounts;
use App\Statistics\Infrastructure\Query\ProjectionFeatureQuery;
use Doctrine\DBAL\Connection;

/**
 * Dual-scope benchmark aggregation in at most two SQL round-trips.
 */
final readonly class BenchmarkAggregationProvider implements BenchmarkAggregationProviderInterface
{
    public function __construct(
        private Connection $connection,
        private ProjectionFeatureQuery $projectionFeatureQuery,
    ) {
    }

    #[\Override]
    public function aggregate(
        StatisticsScopeCriteria $primaryScope,
        StatisticsPeriodBounds $primaryPeriod,
        StatisticsScopeCriteria $comparisonScope,
        StatisticsPeriodBounds $comparisonPeriod,
    ): BenchmarkAggregationResult {
        if ($this->isEmptyScope($primaryScope) && $this->isEmptyScope($comparisonScope)) {
            return BenchmarkAggregationResult::empty();
        }

        [$primaryPred, $primaryParams, $primaryTypes] = BenchmarkSqlFilter::buildSidePredicate(
            $primaryScope,
            $primaryPeriod,
            'primary',
        );
        [$comparisonPred, $comparisonParams, $comparisonTypes] = BenchmarkSqlFilter::buildSidePredicate(
            $comparisonScope,
            $comparisonPeriod,
            'comparison',
        );

        if ('1 = 0' === $primaryPred && '1 = 0' === $comparisonPred) {
            return BenchmarkAggregationResult::empty();
        }

        $params = array_merge($primaryParams, $comparisonParams);
        $types = array_merge($primaryTypes, $comparisonTypes);

        $coreRow = $this->fetchCoreMetrics($primaryPred, $comparisonPred, $params, $types);
        [$primaryPredAliased] = BenchmarkSqlFilter::buildSidePredicate($primaryScope, $primaryPeriod, 'primary', 'p');
        [$comparisonPredAliased] = BenchmarkSqlFilter::buildSidePredicate($comparisonScope, $comparisonPeriod, 'comparison', 'p');
        $distributionRows = $this->fetchDistributionRows(
            $primaryPred,
            $comparisonPred,
            $primaryPredAliased,
            $comparisonPredAliased,
            $params,
            $types,
        );

        return new BenchmarkAggregationResult(
            $this->mapSideCounts($coreRow, 'primary'),
            $this->mapSideCounts($coreRow, 'comparison'),
            $distributionRows,
        );
    }

    /**
     * @param array<string, mixed>                             $params
     * @param array<string, \Doctrine\DBAL\ArrayParameterType> $types
     *
     * @return array<string, mixed>
     */
    private function fetchCoreMetrics(
        string $primaryPred,
        string $comparisonPred,
        array $params,
        array $types,
    ): array {
        $hasExtended = $this->projectionFeatureQuery->hasExtendedClinicalFeatureColumns();
        $shockFilter = $hasExtended ? 'is_shock = true' : 'false';
        $pregnantFilter = $hasExtended ? 'is_pregnant = true' : 'false';
        $workAccidentFilter = $hasExtended ? 'is_work_accident = true' : 'false';
        $countSelect = $this->dualCountSelectSql(
            $primaryPred,
            $comparisonPred,
            $shockFilter,
            $pregnantFilter,
            $workAccidentFilter,
        );

        $unionWhere = sprintf('(%s OR %s)', $primaryPred, $comparisonPred);

        $sql = <<<SQL
SELECT
    {$countSelect},
    PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY age)
        FILTER (WHERE age IS NOT NULL AND {$primaryPred}) AS primary_median_age,
    PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY age)
        FILTER (WHERE age IS NOT NULL AND {$comparisonPred}) AS comparison_median_age,
    PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY transport_time_minutes)
        FILTER (WHERE {$primaryPred}) AS primary_median_transport,
    PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY transport_time_minutes)
        FILTER (WHERE {$comparisonPred}) AS comparison_median_transport,
    AVG(transport_time_minutes) FILTER (WHERE {$primaryPred}) AS primary_mean_transport,
    AVG(transport_time_minutes) FILTER (WHERE {$comparisonPred}) AS comparison_mean_transport
FROM allocation_stats_projection
WHERE {$unionWhere}
SQL;

        $row = $this->connection->fetchAssociative($sql, $params, $types);

        return false === $row ? [] : $row;
    }

    /**
     * @param array<string, mixed>                             $params
     * @param array<string, \Doctrine\DBAL\ArrayParameterType> $types
     *
     * @return list<BenchmarkDistributionRow>
     */
    private function fetchDistributionRows(
        string $primaryPred,
        string $comparisonPred,
        string $primaryPredAliased,
        string $comparisonPredAliased,
        array $params,
        array $types,
    ): array {
        $unionWhere = sprintf('(%s OR %s)', $primaryPred, $comparisonPred);
        $ageBucketCase = StatisticsAgeGroupBucketSql::CASE_EXPRESSION;
        $transportTimeBucketCase = StatisticsTransportTimeBucketSql::CASE_EXPRESSION;
        $maleCode = AllocationStatsGenderProjectionCode::Male->value;
        $femaleCode = AllocationStatsGenderProjectionCode::Female->value;
        $otherCode = AllocationStatsGenderProjectionCode::Other->value;

        $sql = <<<SQL
WITH scoped AS (
    SELECT
        indication_normalized_id,
        gender_code,
        age,
        created_weekday,
        day_time_bucket_code,
        shift_bucket_code,
        transport_time_minutes,
        transport_type_code,
        urgency_code,
        ({$primaryPred}) AS is_primary,
        ({$comparisonPred}) AS is_comparison
    FROM allocation_stats_projection
    WHERE {$unionWhere}
)
SELECT 'gender' AS dimension, 'male' AS bucket_key, NULL::text AS bucket_label,
    COUNT(*) FILTER (WHERE is_primary)::int AS primary_count,
    COUNT(*) FILTER (WHERE is_comparison)::int AS comparison_count
FROM scoped WHERE gender_code = {$maleCode}
UNION ALL
SELECT 'gender', 'female', NULL,
    COUNT(*) FILTER (WHERE is_primary)::int,
    COUNT(*) FILTER (WHERE is_comparison)::int
FROM scoped WHERE gender_code = {$femaleCode}
UNION ALL
SELECT 'gender', 'other', NULL,
    COUNT(*) FILTER (WHERE is_primary)::int,
    COUNT(*) FILTER (WHERE is_comparison)::int
FROM scoped WHERE gender_code = {$otherCode}
UNION ALL
SELECT 'gender', 'unknown', NULL,
    COUNT(*) FILTER (WHERE is_primary)::int,
    COUNT(*) FILTER (WHERE is_comparison)::int
FROM scoped WHERE gender_code IS NULL
UNION ALL
SELECT 'age_group', bucket, NULL,
    COUNT(*) FILTER (WHERE is_primary)::int,
    COUNT(*) FILTER (WHERE is_comparison)::int
FROM (SELECT {$ageBucketCase} AS bucket, is_primary, is_comparison FROM scoped) grouped
GROUP BY bucket
UNION ALL
SELECT 'transport_time', bucket, NULL,
    COUNT(*) FILTER (WHERE is_primary)::int,
    COUNT(*) FILTER (WHERE is_comparison)::int
FROM (SELECT {$transportTimeBucketCase} AS bucket, is_primary, is_comparison FROM scoped) grouped
GROUP BY bucket
UNION ALL
SELECT 'transport_type', transport_type_code::text, NULL,
    COUNT(*) FILTER (WHERE is_primary)::int,
    COUNT(*) FILTER (WHERE is_comparison)::int
FROM scoped
WHERE transport_type_code IS NOT NULL
GROUP BY transport_type_code
UNION ALL
SELECT 'day_time_bucket', day_time_bucket_code::text, NULL,
    COUNT(*) FILTER (WHERE is_primary)::int,
    COUNT(*) FILTER (WHERE is_comparison)::int
FROM scoped
WHERE day_time_bucket_code IS NOT NULL
GROUP BY day_time_bucket_code
UNION ALL
SELECT 'shift_bucket', shift_bucket_code::text, NULL,
    COUNT(*) FILTER (WHERE is_primary)::int,
    COUNT(*) FILTER (WHERE is_comparison)::int
FROM scoped
WHERE shift_bucket_code IS NOT NULL
GROUP BY shift_bucket_code
UNION ALL
SELECT 'urgency', urgency_code::text, NULL,
    COUNT(*) FILTER (WHERE is_primary)::int,
    COUNT(*) FILTER (WHERE is_comparison)::int
FROM scoped
GROUP BY urgency_code
UNION ALL
SELECT 'day_time_heatmap', created_weekday::text, day_time_bucket_code::text,
    COUNT(*) FILTER (WHERE is_primary)::int,
    COUNT(*) FILTER (WHERE is_comparison)::int
FROM scoped
GROUP BY created_weekday, day_time_bucket_code
UNION ALL
SELECT 'shift_heatmap', created_weekday::text, shift_bucket_code::text,
    COUNT(*) FILTER (WHERE is_primary)::int,
    COUNT(*) FILTER (WHERE is_comparison)::int
FROM scoped
GROUP BY created_weekday, shift_bucket_code
UNION ALL
SELECT 'indication', p.indication_normalized_id::text, n.name,
    COUNT(*) FILTER (WHERE ({$primaryPredAliased}))::int,
    COUNT(*) FILTER (WHERE ({$comparisonPredAliased}))::int
FROM allocation_stats_projection p
LEFT JOIN indication_normalized n ON n.id = p.indication_normalized_id
WHERE ({$primaryPredAliased} OR {$comparisonPredAliased}) AND p.indication_normalized_id IS NOT NULL
GROUP BY p.indication_normalized_id, n.name
SQL;

        /** @var list<array{dimension:string,bucket_key:?string,bucket_label:?string,primary_count:int|string,comparison_count:int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        $result = [];
        foreach ($rows as $row) {
            $bucketKey = $row['bucket_key'] ?? '';
            if ('' === $bucketKey) {
                continue;
            }

            $result[] = new BenchmarkDistributionRow(
                $row['dimension'],
                $bucketKey,
                $row['bucket_label'] ?? null,
                (int) $row['primary_count'],
                (int) $row['comparison_count'],
            );
        }

        return $result;
    }

    private function dualCountSelectSql(
        string $primaryPred,
        string $comparisonPred,
        string $shockFilter,
        string $pregnantFilter,
        string $workAccidentFilter,
    ): string {
        $nightCode = AllocationStatsDayTimeBucketProjectionCode::Night->value;
        $maleCode = AllocationStatsGenderProjectionCode::Male->value;
        $femaleCode = AllocationStatsGenderProjectionCode::Female->value;
        $otherCode = AllocationStatsGenderProjectionCode::Other->value;
        $emergencyCode = AllocationStatsUrgencyProjectionCode::Emergency->value;
        $inpatientCode = AllocationStatsUrgencyProjectionCode::Inpatient->value;
        $outpatientCode = AllocationStatsUrgencyProjectionCode::Outpatient->value;

        $metrics = [
            ['total', 'true'],
            ['with_physician', 'is_with_physician = true'],
            ['resus', 'requires_resus = true'],
            ['cathlab', 'requires_cathlab = true'],
            ['cpr', 'is_cpr = true'],
            ['ventilated', 'is_ventilated = true'],
            ['shock', $shockFilter],
            ['pregnant', $pregnantFilter],
            ['work_accident', $workAccidentFilter],
            ['infectious', 'infection_id IS NOT NULL'],
            ['urgency_emergency', sprintf('urgency_code = %d', $emergencyCode)],
            ['urgency_inpatient', sprintf('urgency_code = %d', $inpatientCode)],
            ['urgency_outpatient', sprintf('urgency_code = %d', $outpatientCode)],
            ['night_daytime', sprintf('day_time_bucket_code = %d', $nightCode)],
            ['weekend', 'created_weekday IN (6, 7)'],
            ['age_80_plus', 'age >= 80'],
            ['male', sprintf('gender_code = %d', $maleCode)],
            ['female', sprintf('gender_code = %d', $femaleCode)],
            ['gender_other', sprintf('gender_code = %d', $otherCode)],
        ];

        $parts = [];
        foreach ($metrics as [$alias, $condition]) {
            $parts[] = sprintf(
                'COUNT(*) FILTER (WHERE %s AND %s)::int AS primary_%s',
                $condition,
                $primaryPred,
                $alias,
            );
            $parts[] = sprintf(
                'COUNT(*) FILTER (WHERE %s AND %s)::int AS comparison_%s',
                $condition,
                $comparisonPred,
                $alias,
            );
        }

        return implode(",\n    ", $parts);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapSideCounts(array $row, string $side): BenchmarkSideCounts
    {
        return new BenchmarkSideCounts(
            (int) ($row[sprintf('%s_total', $side)] ?? 0),
            (int) ($row[sprintf('%s_with_physician', $side)] ?? 0),
            (int) ($row[sprintf('%s_resus', $side)] ?? 0),
            (int) ($row[sprintf('%s_cathlab', $side)] ?? 0),
            (int) ($row[sprintf('%s_cpr', $side)] ?? 0),
            (int) ($row[sprintf('%s_ventilated', $side)] ?? 0),
            (int) ($row[sprintf('%s_shock', $side)] ?? 0),
            (int) ($row[sprintf('%s_pregnant', $side)] ?? 0),
            (int) ($row[sprintf('%s_work_accident', $side)] ?? 0),
            (int) ($row[sprintf('%s_infectious', $side)] ?? 0),
            (int) ($row[sprintf('%s_urgency_emergency', $side)] ?? 0),
            (int) ($row[sprintf('%s_urgency_inpatient', $side)] ?? 0),
            (int) ($row[sprintf('%s_urgency_outpatient', $side)] ?? 0),
            (int) ($row[sprintf('%s_night_daytime', $side)] ?? 0),
            (int) ($row[sprintf('%s_weekend', $side)] ?? 0),
            (int) ($row[sprintf('%s_age_80_plus', $side)] ?? 0),
            (int) ($row[sprintf('%s_male', $side)] ?? 0),
            (int) ($row[sprintf('%s_female', $side)] ?? 0),
            (int) ($row[sprintf('%s_gender_other', $side)] ?? 0),
            $this->toFloatOrNull($row[sprintf('%s_median_age', $side)] ?? null),
            $this->toFloatOrNull($row[sprintf('%s_median_transport', $side)] ?? null),
            $this->toFloatOrNull($row[sprintf('%s_mean_transport', $side)] ?? null),
        );
    }

    private function isEmptyScope(StatisticsScopeCriteria $scope): bool
    {
        return \is_array($scope->hospitalIds) && [] === $scope->hospitalIds;
    }

    private function toFloatOrNull(mixed $value): ?float
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return (float) $value;
    }
}
