<?php

declare(strict_types=1);

namespace App\Statistics\DataQuality\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use Doctrine\DBAL\Connection;

final readonly class DataQualityHospitalAllocationCountsQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array<int, int> hospitalId => allocation count
     */
    public function fetch(
        int $indicationId,
        StatisticsPeriodBounds $period,
        StatisticsScopeCriteria $scope,
    ): array {
        if (\is_array($scope->hospitalIds) && [] === $scope->hospitalIds) {
            return [];
        }

        [$where, $params, $types] = DataQualitySqlFilter::buildWhere(
            $indicationId,
            $period->from,
            $period->toExclusive,
            $scope,
        );

        $sql = <<<SQL
SELECT hospital_id, COUNT(*)::int AS allocation_count
FROM allocation_stats_projection
WHERE {$where}
GROUP BY hospital_id
ORDER BY hospital_id ASC
SQL;

        /** @var list<array{hospital_id: int|string, allocation_count: int|string}> $rows */
        $rows = $this->connection->executeQuery($sql, $params, $types)->fetchAllAssociative();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['hospital_id']] = (int) $row['allocation_count'];
        }

        return $counts;
    }
}
