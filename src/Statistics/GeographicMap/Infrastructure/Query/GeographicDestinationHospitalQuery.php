<?php

declare(strict_types=1);

namespace App\Statistics\GeographicMap\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsDrawerFilter;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\CaseFlow\Infrastructure\Query\CaseFlowSqlFilter;
use Doctrine\DBAL\Connection;

final readonly class GeographicDestinationHospitalQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<GeographicDestinationHospitalRow>
     */
    public function fetch(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        ?int $originStateId = null,
        ?StatisticsDrawerFilter $drawerFilter = null,
    ): array {
        if (CaseFlowSqlFilter::isImpossibleScope($scope, $originStateId)) {
            return [];
        }

        [$where, $params, $types] = CaseFlowSqlFilter::buildScopePeriodWhere(
            $from,
            $toExclusive,
            $scope,
            'asp',
            $originStateId,
            $drawerFilter,
        );

        $sql = <<<SQL
SELECT
    h.id AS hospital_id,
    h.name AS hospital_name,
    h.latitude,
    h.longitude,
    MAX(asp.hospital_tier_code) AS hospital_tier_code,
    MAX(asp.hospital_location_code) AS hospital_location_code,
    h.dispatch_area_id,
    COUNT(*)::int AS case_count
FROM allocation_stats_projection asp
INNER JOIN hospital h ON h.id = asp.hospital_id
WHERE {$where}
GROUP BY h.id, h.name, h.latitude, h.longitude, h.dispatch_area_id
ORDER BY case_count DESC
SQL;

        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        return array_map(
            static fn (array $row): GeographicDestinationHospitalRow => new GeographicDestinationHospitalRow(
                (int) $row['hospital_id'],
                (string) $row['hospital_name'],
                null !== $row['latitude'] ? (float) $row['latitude'] : null,
                null !== $row['longitude'] ? (float) $row['longitude'] : null,
                (int) $row['case_count'],
                null !== $row['hospital_tier_code'] ? (int) $row['hospital_tier_code'] : null,
                null !== $row['hospital_location_code'] ? (int) $row['hospital_location_code'] : null,
                null !== $row['dispatch_area_id'] ? (int) $row['dispatch_area_id'] : null,
            ),
            $rows,
        );
    }
}
