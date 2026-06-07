<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\CaseFlow\Infrastructure\Query\Dto\CaseFlowDestinationPoolRow;
use Doctrine\DBAL\Connection;

final readonly class CaseFlowDestinationStructureQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<CaseFlowDestinationPoolRow>
     */
    public function fetchByTier(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
    ): array {
        return $this->fetchGrouped($from, $toExclusive, $scope, 'asp.hospital_tier_code::TEXT', false);
    }

    /**
     * @return list<CaseFlowDestinationPoolRow>
     */
    public function fetchByLocation(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
    ): array {
        return $this->fetchGrouped($from, $toExclusive, $scope, 'asp.hospital_location_code::TEXT', false);
    }

    /**
     * @return list<CaseFlowDestinationPoolRow>
     */
    public function fetchBySize(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
    ): array {
        return $this->fetchGrouped($from, $toExclusive, $scope, 'h.size', true);
    }

    /**
     * @return list<CaseFlowDestinationPoolRow>
     */
    private function fetchGrouped(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        string $column,
        bool $joinHospital,
    ): array {
        if (\is_array($scope->hospitalIds) && [] === $scope->hospitalIds) {
            return [];
        }

        [$where, $params, $types] = CaseFlowSqlFilter::buildScopePeriodWhere($from, $toExclusive, $scope);
        $join = $joinHospital ? 'INNER JOIN hospital h ON h.id = asp.hospital_id' : '';

        $sql = <<<SQL
SELECT
    COALESCE({$column}, 'unknown') AS pool_key,
    COUNT(*) AS case_count,
    COUNT(DISTINCT asp.hospital_id) AS hospital_count
FROM allocation_stats_projection asp
{$join}
WHERE {$where}
GROUP BY COALESCE({$column}, 'unknown')
ORDER BY case_count DESC
SQL;

        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        return array_map(
            static fn (array $row): CaseFlowDestinationPoolRow => new CaseFlowDestinationPoolRow(
                (string) $row['pool_key'],
                (int) $row['case_count'],
                (int) $row['hospital_count'],
            ),
            $rows,
        );
    }
}
