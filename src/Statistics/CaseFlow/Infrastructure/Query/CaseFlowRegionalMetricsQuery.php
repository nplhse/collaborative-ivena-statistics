<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\Mapping\AllocationStatsHospitalTierProjectionCode;
use App\Statistics\Application\Mapping\StatisticsTransportTimeSql;
use App\Statistics\CaseFlow\Infrastructure\Query\Dto\CaseFlowRegionalMetricsRow;
use Doctrine\DBAL\Connection;

final readonly class CaseFlowRegionalMetricsQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function fetch(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
    ): CaseFlowRegionalMetricsRow {
        if (\is_array($scope->hospitalIds) && [] === $scope->hospitalIds) {
            return new CaseFlowRegionalMetricsRow(0, 0, 0, 0, null, null);
        }

        [$where, $params, $types] = CaseFlowSqlFilter::buildScopePeriodWhere($from, $toExclusive, $scope);
        $fullTier = AllocationStatsHospitalTierProjectionCode::Full->value;
        $meanTransport = StatisticsTransportTimeSql::meanPreciseMinutes('asp');
        $medianTransport = StatisticsTransportTimeSql::medianPreciseMinutes('asp');

        $sql = <<<SQL
SELECT
    COUNT(*) AS total_cases,
    COUNT(*) FILTER (WHERE asp.dispatch_area_id = h.dispatch_area_id) AS regional_cases,
    COUNT(*) FILTER (WHERE asp.hospital_tier_code = {$fullTier}) AS full_tier_cases,
    COUNT(*) FILTER (WHERE asp.urgency_code = 1) AS emergency_cases,
    {$meanTransport} AS mean_transport_minutes,
    {$medianTransport} AS median_transport_minutes
FROM allocation_stats_projection asp
INNER JOIN hospital h ON h.id = asp.hospital_id
WHERE {$where}
SQL;

        $row = $this->connection->fetchAssociative($sql, $params, $types);
        if (false === $row) {
            return new CaseFlowRegionalMetricsRow(0, 0, 0, 0, null, null);
        }

        return new CaseFlowRegionalMetricsRow(
            (int) $row['total_cases'],
            (int) $row['regional_cases'],
            (int) $row['full_tier_cases'],
            (int) $row['emergency_cases'],
            null !== $row['mean_transport_minutes'] ? (float) $row['mean_transport_minutes'] : null,
            null !== $row['median_transport_minutes'] ? (float) $row['median_transport_minutes'] : null,
        );
    }
}
