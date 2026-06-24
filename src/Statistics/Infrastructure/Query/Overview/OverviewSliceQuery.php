<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\Overview;

use App\Statistics\Application\Mapping\StatisticsTransportTimeBucketSql;
use App\Statistics\Infrastructure\Query\Overview\Dto\OverviewSliceData;
use Doctrine\DBAL\Connection;

final readonly class OverviewSliceQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function __invoke(OverviewQueryCriteria $criteria): OverviewSliceData
    {
        if ($criteria->hasEmptyHospitalScope()) {
            return OverviewSliceData::empty();
        }

        $needsAllTimeMonthlyRows = $criteria->from instanceof \DateTimeImmutable || $criteria->toExclusive instanceof \DateTimeImmutable;
        $transportBucketCase = StatisticsTransportTimeBucketSql::CASE_EXPRESSION;

        if ($needsAllTimeMonthlyRows) {
            [$scopedWhere, $params] = OverviewProjectionSqlFilter::buildWhereClause($criteria);
            [$allTimeWhere] = OverviewProjectionSqlFilter::buildWhereClause(new OverviewQueryCriteria(
                null,
                null,
                $criteria->hospitalIds,
            ));

            $sql = <<<SQL
WITH scoped_slice AS (
    SELECT
        transport_time_minutes,
        created_weekday,
        day_time_bucket_code,
        shift_bucket_code
    FROM allocation_stats_projection
    WHERE {$scopedWhere}
),
all_time_slice AS (
    SELECT
        created_year,
        created_month
    FROM allocation_stats_projection
    WHERE {$allTimeWhere}
)
SELECT 'time_series_all_time' AS slice_kind, created_year::text AS dim1, created_month::text AS dim2, COUNT(*)::int AS count
FROM all_time_slice GROUP BY created_year, created_month
UNION ALL
SELECT 'transport_time', bucket, NULL, COUNT(*)::int
FROM (SELECT {$transportBucketCase} AS bucket FROM scoped_slice) grouped
GROUP BY bucket
UNION ALL
SELECT 'day_time_heatmap', created_weekday::text, day_time_bucket_code::text, COUNT(*)::int
FROM scoped_slice GROUP BY created_weekday, day_time_bucket_code
UNION ALL
SELECT 'shift_heatmap', created_weekday::text, shift_bucket_code::text, COUNT(*)::int
FROM scoped_slice GROUP BY created_weekday, shift_bucket_code
SQL;
        } else {
            [$where, $params] = OverviewProjectionSqlFilter::buildWhereClause($criteria);

            $sql = <<<SQL
WITH slice AS (
    SELECT
        created_year,
        created_month,
        transport_time_minutes,
        created_weekday,
        day_time_bucket_code,
        shift_bucket_code
    FROM allocation_stats_projection
    WHERE {$where}
)
SELECT 'time_series' AS slice_kind, created_year::text AS dim1, created_month::text AS dim2, COUNT(*)::int AS count
FROM slice GROUP BY created_year, created_month
UNION ALL
SELECT 'transport_time', bucket, NULL, COUNT(*)::int
FROM (SELECT {$transportBucketCase} AS bucket FROM slice) grouped
GROUP BY bucket
UNION ALL
SELECT 'day_time_heatmap', created_weekday::text, day_time_bucket_code::text, COUNT(*)::int
FROM slice GROUP BY created_weekday, day_time_bucket_code
UNION ALL
SELECT 'shift_heatmap', created_weekday::text, shift_bucket_code::text, COUNT(*)::int
FROM slice GROUP BY created_weekday, shift_bucket_code
SQL;
        }

        /** @var list<array{slice_kind:string,dim1:?string,dim2:?string,count:int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return $this->parseRows($rows);
    }

    /**
     * @param list<array{slice_kind:string,dim1:?string,dim2:?string,count:int|string}> $rows
     */
    private function parseRows(array $rows): OverviewSliceData
    {
        $monthlyRows = [];
        $transportTimeBucketCounts = [];
        $dayTimeHeatmapCells = [];
        $shiftHeatmapCells = [];

        foreach ($rows as $row) {
            $count = (int) $row['count'];
            $kind = $row['slice_kind'];
            $dim1 = $row['dim1'] ?? '';
            $dim2 = $row['dim2'] ?? '';

            match ($kind) {
                'time_series', 'time_series_all_time' => $monthlyRows[] = [
                    'year' => (int) $dim1,
                    'month' => (int) $dim2,
                    'count' => $count,
                ],
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

        return new OverviewSliceData(
            $monthlyRows,
            [],
            $transportTimeBucketCounts,
            $dayTimeHeatmapCells,
            $shiftHeatmapCells,
        );
    }
}
