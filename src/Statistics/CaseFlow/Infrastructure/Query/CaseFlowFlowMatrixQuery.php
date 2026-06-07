<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\CaseFlow\Infrastructure\Query\Dto\CaseFlowFlowMatrixCell;
use Doctrine\DBAL\Connection;

final readonly class CaseFlowFlowMatrixQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<CaseFlowFlowMatrixCell>
     */
    public function fetch(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
    ): array {
        if (\is_array($scope->hospitalIds) && [] === $scope->hospitalIds) {
            return [];
        }

        [$where, $params, $types] = CaseFlowSqlFilter::buildScopePeriodWhere($from, $toExclusive, $scope);

        $sql = <<<SQL
SELECT
    asp.dispatch_area_id,
    da.name AS origin_name,
    asp.hospital_tier_code AS destination_pool_code,
    COUNT(*) AS case_count,
    COUNT(DISTINCT asp.hospital_id) AS hospital_count
FROM allocation_stats_projection asp
INNER JOIN dispatch_area da ON da.id = asp.dispatch_area_id
WHERE {$where}
GROUP BY asp.dispatch_area_id, da.name, asp.hospital_tier_code
ORDER BY case_count DESC
SQL;

        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        return array_map(
            static fn (array $row): CaseFlowFlowMatrixCell => new CaseFlowFlowMatrixCell(
                (int) $row['dispatch_area_id'],
                (string) $row['origin_name'],
                null !== $row['destination_pool_code'] ? (int) $row['destination_pool_code'] : null,
                (int) $row['case_count'],
                (int) $row['hospital_count'],
            ),
            $rows,
        );
    }
}
