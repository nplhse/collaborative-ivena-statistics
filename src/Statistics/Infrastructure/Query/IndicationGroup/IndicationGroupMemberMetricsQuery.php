<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\IndicationGroup;

use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\Mapping\AllocationStatsUrgencyProjectionCode;
use App\Statistics\Infrastructure\Query\IndicationDashboard\IndicationDashboardSqlFilter;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class IndicationGroupMemberMetricsQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param list<int> $indicationIds
     *
     * @return list<array{indicationId: int, total: int, withPhysician: int, resus: int, urgencyEmergency: int}>
     */
    public function fetch(
        array $indicationIds,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
    ): array {
        if ([] === $indicationIds) {
            return [];
        }

        if (\is_array($scope->hospitalIds) && [] === $scope->hospitalIds) {
            return [];
        }

        [$where, $params, $types] = IndicationDashboardSqlFilter::buildScopePeriodWhere($from, $toExclusive, $scope);
        $types['indication_ids'] = ArrayParameterType::INTEGER;
        $params['indication_ids'] = array_map(static fn (int $id): int => $id, $indicationIds);

        $emergencyCode = AllocationStatsUrgencyProjectionCode::Emergency->value;

        $sql = <<<SQL
SELECT
    indication_normalized_id AS indication_id,
    COUNT(*)::int AS total,
    COUNT(*) FILTER (WHERE is_with_physician = true)::int AS with_physician,
    COUNT(*) FILTER (WHERE requires_resus = true)::int AS resus,
    COUNT(*) FILTER (WHERE urgency_code = {$emergencyCode})::int AS urgency_emergency
FROM allocation_stats_projection
WHERE {$where} AND indication_normalized_id IN (:indication_ids)
GROUP BY indication_normalized_id
ORDER BY total DESC
SQL;

        /** @var list<array{indication_id:int|string,total:int|string,with_physician:int|string,resus:int|string,urgency_emergency:int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        return array_map(
            static fn (array $row): array => [
                'indicationId' => (int) $row['indication_id'],
                'total' => (int) $row['total'],
                'withPhysician' => (int) $row['with_physician'],
                'resus' => (int) $row['resus'],
                'urgencyEmergency' => (int) $row['urgency_emergency'],
            ],
            $rows,
        );
    }
}
