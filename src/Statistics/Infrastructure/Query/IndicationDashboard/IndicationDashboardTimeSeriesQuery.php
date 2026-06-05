<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\IndicationDashboard;

use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use Doctrine\DBAL\Connection;

final readonly class IndicationDashboardTimeSeriesQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<array{year:int,month:int,count:int}>
     */
    public function countByMonth(
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
SELECT created_year AS year, created_month AS month, COUNT(*)::int AS count
FROM allocation_stats_projection
WHERE {$where}
GROUP BY created_year, created_month
ORDER BY created_year ASC, created_month ASC
SQL;

        /** @var list<array{year:int|string,month:int|string,count:int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return array_map(
            static fn (array $row): array => [
                'year' => (int) $row['year'],
                'month' => (int) $row['month'],
                'count' => (int) $row['count'],
            ],
            $rows,
        );
    }
}
