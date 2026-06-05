<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\IndicationDashboard;

use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use Doctrine\DBAL\Connection;

final readonly class IndicationDashboardTemporalQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * ISO weekday (1=Mon … 7=Sun) => count.
     *
     * @return array<int, int>
     */
    public function weekdayCounts(
        int $indicationId,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
    ): array {
        if (\is_array($scope->hospitalIds) && [] === $scope->hospitalIds) {
            return [];
        }

        [$where, $params] = IndicationDashboardSqlFilter::buildScopePeriodWhere($from, $toExclusive, $scope);
        $params['indication_id'] = $indicationId;
        $where .= ' AND indication_normalized_id = :indication_id';

        $sql = <<<SQL
SELECT created_weekday AS weekday, COUNT(*)::int AS count
FROM allocation_stats_projection
WHERE {$where}
GROUP BY created_weekday
ORDER BY created_weekday ASC
SQL;

        /** @var list<array{weekday:int|string,count:int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params);

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['weekday']] = (int) $row['count'];
        }

        return $result;
    }

    /**
     * @return list<array{weekday:int,dayTimeBucketCode:int,count:int}>
     */
    public function heatmapCells(
        int $indicationId,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
    ): array {
        if (\is_array($scope->hospitalIds) && [] === $scope->hospitalIds) {
            return [];
        }

        [$where, $params] = IndicationDashboardSqlFilter::buildScopePeriodWhere($from, $toExclusive, $scope);
        $params['indication_id'] = $indicationId;
        $where .= ' AND indication_normalized_id = :indication_id';

        $sql = <<<SQL
SELECT created_weekday AS weekday, day_time_bucket_code AS day_time_bucket_code, COUNT(*)::int AS count
FROM allocation_stats_projection
WHERE {$where}
GROUP BY created_weekday, day_time_bucket_code
ORDER BY created_weekday ASC, day_time_bucket_code ASC
SQL;

        /** @var list<array{weekday:int|string,day_time_bucket_code:int|string,count:int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return array_map(
            static fn (array $row): array => [
                'weekday' => (int) $row['weekday'],
                'dayTimeBucketCode' => (int) $row['day_time_bucket_code'],
                'count' => (int) $row['count'],
            ],
            $rows,
        );
    }

    /**
     * @return list<array{weekday:int,shiftBucketCode:int,count:int}>
     */
    public function shiftHeatmapCells(
        int $indicationId,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
    ): array {
        if (\is_array($scope->hospitalIds) && [] === $scope->hospitalIds) {
            return [];
        }

        [$where, $params] = IndicationDashboardSqlFilter::buildScopePeriodWhere($from, $toExclusive, $scope);
        $params['indication_id'] = $indicationId;
        $where .= ' AND indication_normalized_id = :indication_id';

        $sql = <<<SQL
SELECT created_weekday AS weekday, shift_bucket_code AS shift_bucket_code, COUNT(*)::int AS count
FROM allocation_stats_projection
WHERE {$where}
GROUP BY created_weekday, shift_bucket_code
ORDER BY created_weekday ASC, shift_bucket_code ASC
SQL;

        /** @var list<array{weekday:int|string,shift_bucket_code:int|string,count:int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return array_map(
            static fn (array $row): array => [
                'weekday' => (int) $row['weekday'],
                'shiftBucketCode' => (int) $row['shift_bucket_code'],
                'count' => (int) $row['count'],
            ],
            $rows,
        );
    }
}
