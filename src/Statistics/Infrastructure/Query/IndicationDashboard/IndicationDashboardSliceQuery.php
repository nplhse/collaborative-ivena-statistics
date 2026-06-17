<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\IndicationDashboard;

use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use App\Statistics\Application\Mapping\StatisticsAgeGroupBucketSql;
use App\Statistics\Application\Mapping\StatisticsTransportTimeBucketSql;
use App\Statistics\Infrastructure\Query\IndicationDashboard\Dto\IndicationDashboardSliceData;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Single-scan aggregation for all indication-only dashboard dimensions.
 *
 * @see docs/indication-dashboard-performance.md
 */
final readonly class IndicationDashboardSliceQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param list<int> $indicationIds
     */
    public function fetch(
        array $indicationIds,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
    ): IndicationDashboardSliceData {
        if ([] === $indicationIds) {
            return IndicationDashboardSliceData::empty();
        }

        if (\is_array($scope->hospitalIds) && [] === $scope->hospitalIds) {
            return IndicationDashboardSliceData::empty();
        }

        [$where, $params, $types] = IndicationDashboardSqlFilter::buildScopePeriodWhere($from, $toExclusive, $scope);
        $types['indication_ids'] = ArrayParameterType::INTEGER;
        $params['indication_ids'] = array_map(static fn (int $id): int => $id, $indicationIds);

        $ageBucketCase = StatisticsAgeGroupBucketSql::CASE_EXPRESSION;
        $transportBucketCase = StatisticsTransportTimeBucketSql::CASE_EXPRESSION;
        $maleCode = AllocationStatsGenderProjectionCode::Male->value;
        $femaleCode = AllocationStatsGenderProjectionCode::Female->value;
        $otherCode = AllocationStatsGenderProjectionCode::Other->value;

        $sql = <<<SQL
WITH slice AS (
    SELECT
        created_year,
        created_month,
        gender_code,
        age,
        transport_time_minutes,
        created_weekday,
        day_time_bucket_code,
        shift_bucket_code
    FROM allocation_stats_projection
    WHERE {$where} AND indication_normalized_id IN (:indication_ids)
)
SELECT 'gender' AS slice_kind, 'male' AS dim1, NULL::text AS dim2, NULL::text AS dim3, COUNT(*)::int AS count
FROM slice WHERE gender_code = {$maleCode}
UNION ALL
SELECT 'gender', 'female', NULL, NULL, COUNT(*)::int FROM slice WHERE gender_code = {$femaleCode}
UNION ALL
SELECT 'gender', 'other', NULL, NULL, COUNT(*)::int FROM slice WHERE gender_code = {$otherCode}
UNION ALL
SELECT 'gender', 'unknown', NULL, NULL, COUNT(*)::int FROM slice WHERE gender_code IS NULL
UNION ALL
SELECT 'time_series', created_year::text, created_month::text, NULL, COUNT(*)::int
FROM slice GROUP BY created_year, created_month
UNION ALL
SELECT 'age_group', bucket, NULL, NULL, COUNT(*)::int
FROM (SELECT {$ageBucketCase} AS bucket FROM slice) grouped
GROUP BY bucket
UNION ALL
SELECT 'transport_time', bucket, NULL, NULL, COUNT(*)::int
FROM (SELECT {$transportBucketCase} AS bucket FROM slice) grouped
GROUP BY bucket
UNION ALL
SELECT 'day_time_heatmap', created_weekday::text, day_time_bucket_code::text, NULL, COUNT(*)::int
FROM slice GROUP BY created_weekday, day_time_bucket_code
UNION ALL
SELECT 'shift_heatmap', created_weekday::text, shift_bucket_code::text, NULL, COUNT(*)::int
FROM slice GROUP BY created_weekday, shift_bucket_code
SQL;

        /** @var list<array{slice_kind:string,dim1:?string,dim2:?string,dim3:?string,count:int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        return $this->parseRows($rows);
    }

    /**
     * @param list<array{slice_kind:string,dim1:?string,dim2:?string,dim3:?string,count:int|string}> $rows
     */
    private function parseRows(array $rows): IndicationDashboardSliceData
    {
        $monthlyRows = [];
        /** @var array{male: int, female: int, other: int, unknown: int} $genderCounts */
        $genderCounts = ['male' => 0, 'female' => 0, 'other' => 0, 'unknown' => 0];
        $ageGroupCounts = [];
        $transportTimeBucketCounts = [];
        $dayTimeHeatmapCells = [];
        $shiftHeatmapCells = [];

        foreach ($rows as $row) {
            $count = (int) $row['count'];
            $kind = $row['slice_kind'];
            $dim1 = $row['dim1'] ?? '';
            $dim2 = $row['dim2'] ?? '';

            match ($kind) {
                'gender' => match ($dim1) {
                    'male', 'female', 'other', 'unknown' => $genderCounts[$dim1] = $count,
                    default => null,
                },
                'time_series' => $monthlyRows[] = [
                    'year' => (int) $dim1,
                    'month' => (int) $dim2,
                    'count' => $count,
                ],
                'age_group' => $ageGroupCounts[$dim1] = $count,
                'transport_time' => $transportTimeBucketCounts[$dim1] = $count,
                'day_time_heatmap' => $dayTimeHeatmapCells[] = [
                    'weekday' => (int) $dim1,
                    'dayTimeBucketCode' => (int) $dim2,
                    'count' => $count,
                ],
                'shift_heatmap' => $shiftHeatmapCells[] = [
                    'weekday' => (int) $dim1,
                    'shiftBucketCode' => (int) $dim2,
                    'count' => $count,
                ],
                default => null,
            };
        }

        usort(
            $monthlyRows,
            static fn (array $a, array $b): int => $a['year'] <=> $b['year'] ?: $a['month'] <=> $b['month'],
        );

        return new IndicationDashboardSliceData(
            $monthlyRows,
            $genderCounts,
            $ageGroupCounts,
            $transportTimeBucketCounts,
            $dayTimeHeatmapCells,
            $shiftHeatmapCells,
        );
    }
}
