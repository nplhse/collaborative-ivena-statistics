<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\IndicationCompare;

use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use App\Statistics\Application\Mapping\StatisticsAgeGroupBucketSql;
use App\Statistics\Application\Mapping\StatisticsTransportTimeBucketSql;
use App\Statistics\Infrastructure\Query\IndicationCompare\Dto\IndicationCompareDistributionRow;
use App\Statistics\Infrastructure\Query\IndicationDashboard\IndicationDashboardSqlFilter;
use Doctrine\DBAL\Connection;

/**
 * Single-scan slice dimensions for two indications in one scope.
 */
final readonly class IndicationCompareSliceQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<IndicationCompareDistributionRow>
     */
    public function fetch(
        int $indicationIdA,
        int $indicationIdB,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
    ): array {
        if (\is_array($scope->hospitalIds) && [] === $scope->hospitalIds) {
            return [];
        }

        $predA = 'indication_normalized_id = :id_a';
        $predB = 'indication_normalized_id = :id_b';

        [$scopeWhere, $params, $types] = IndicationDashboardSqlFilter::buildScopePeriodWhere($from, $toExclusive, $scope);
        $where = sprintf('(%s) AND (%s OR %s)', $scopeWhere, $predA, $predB);
        $params['id_a'] = $indicationIdA;
        $params['id_b'] = $indicationIdB;

        $ageBucketCase = StatisticsAgeGroupBucketSql::CASE_EXPRESSION;
        $transportBucketCase = StatisticsTransportTimeBucketSql::CASE_EXPRESSION;
        $maleCode = AllocationStatsGenderProjectionCode::Male->value;
        $femaleCode = AllocationStatsGenderProjectionCode::Female->value;
        $otherCode = AllocationStatsGenderProjectionCode::Other->value;

        $sql = <<<SQL
WITH scoped AS (
    SELECT
        gender_code,
        age,
        transport_time_minutes,
        transport_type_code,
        created_weekday,
        day_time_bucket_code,
        shift_bucket_code,
        created_year,
        created_month,
        ({$predA}) AS is_side_a,
        ({$predB}) AS is_side_b
    FROM allocation_stats_projection
    WHERE {$where}
)
SELECT 'gender' AS dimension, 'male' AS bucket_key, NULL::text AS bucket_label,
    COUNT(*) FILTER (WHERE is_side_a)::int AS side_a_count,
    COUNT(*) FILTER (WHERE is_side_b)::int AS side_b_count
FROM scoped WHERE gender_code = {$maleCode}
UNION ALL
SELECT 'gender', 'female', NULL,
    COUNT(*) FILTER (WHERE is_side_a)::int,
    COUNT(*) FILTER (WHERE is_side_b)::int
FROM scoped WHERE gender_code = {$femaleCode}
UNION ALL
SELECT 'gender', 'other', NULL,
    COUNT(*) FILTER (WHERE is_side_a)::int,
    COUNT(*) FILTER (WHERE is_side_b)::int
FROM scoped WHERE gender_code = {$otherCode}
UNION ALL
SELECT 'gender', 'unknown', NULL,
    COUNT(*) FILTER (WHERE is_side_a)::int,
    COUNT(*) FILTER (WHERE is_side_b)::int
FROM scoped WHERE gender_code IS NULL
UNION ALL
SELECT 'age_group', bucket, NULL,
    COUNT(*) FILTER (WHERE is_side_a)::int,
    COUNT(*) FILTER (WHERE is_side_b)::int
FROM (SELECT {$ageBucketCase} AS bucket, is_side_a, is_side_b FROM scoped) grouped
GROUP BY bucket
UNION ALL
SELECT 'transport_time', bucket, NULL,
    COUNT(*) FILTER (WHERE is_side_a)::int,
    COUNT(*) FILTER (WHERE is_side_b)::int
FROM (SELECT {$transportBucketCase} AS bucket, is_side_a, is_side_b FROM scoped) grouped
GROUP BY bucket
UNION ALL
SELECT 'transport_type', transport_type_code::text, NULL,
    COUNT(*) FILTER (WHERE is_side_a)::int,
    COUNT(*) FILTER (WHERE is_side_b)::int
FROM scoped
WHERE transport_type_code IS NOT NULL
GROUP BY transport_type_code
UNION ALL
SELECT 'day_time_heatmap', created_weekday::text, day_time_bucket_code::text,
    COUNT(*) FILTER (WHERE is_side_a)::int AS side_a_count,
    COUNT(*) FILTER (WHERE is_side_b)::int AS side_b_count
FROM scoped
WHERE day_time_bucket_code IS NOT NULL
GROUP BY created_weekday, day_time_bucket_code
UNION ALL
SELECT 'shift_heatmap', created_weekday::text, shift_bucket_code::text,
    COUNT(*) FILTER (WHERE is_side_a)::int,
    COUNT(*) FILTER (WHERE is_side_b)::int
FROM scoped
WHERE shift_bucket_code IS NOT NULL
GROUP BY created_weekday, shift_bucket_code
UNION ALL
SELECT 'time_series', created_year::text, created_month::text,
    COUNT(*) FILTER (WHERE is_side_a)::int,
    COUNT(*) FILTER (WHERE is_side_b)::int
FROM scoped
GROUP BY created_year, created_month
SQL;

        /** @var list<array{dimension:string,bucket_key:?string,bucket_label:?string,side_a_count:int|string,side_b_count:int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        $result = [];
        foreach ($rows as $row) {
            $bucketKey = $row['bucket_key'] ?? '';
            if ('' === $bucketKey) {
                continue;
            }

            $result[] = new IndicationCompareDistributionRow(
                $row['dimension'],
                $bucketKey,
                $row['bucket_label'] ?? null,
                (int) $row['side_a_count'],
                (int) $row['side_b_count'],
            );
        }

        return $result;
    }
}
