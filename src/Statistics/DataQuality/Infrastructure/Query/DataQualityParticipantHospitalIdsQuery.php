<?php

declare(strict_types=1);

namespace App\Statistics\DataQuality\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use Doctrine\DBAL\Connection;

final readonly class DataQualityParticipantHospitalIdsQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<int>
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
SELECT DISTINCT hospital_id
FROM allocation_stats_projection
WHERE {$where}
ORDER BY hospital_id ASC
SQL;

        /** @var list<int|string> $raw */
        $raw = $this->connection->executeQuery($sql, $params, $types)->fetchFirstColumn();

        return array_map(static fn (int|string $id): int => (int) $id, $raw);
    }
}
