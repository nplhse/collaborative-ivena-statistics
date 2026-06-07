<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\CaseFlow\Infrastructure\Query\Dto\CaseFlowOriginRow;
use Doctrine\DBAL\Connection;

final readonly class CaseFlowOriginDistributionQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<CaseFlowOriginRow>
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
    COUNT(*) AS case_count,
    COUNT(*) FILTER (WHERE asp.urgency_code = 1) AS emergency_count
FROM allocation_stats_projection asp
INNER JOIN dispatch_area da ON da.id = asp.dispatch_area_id
WHERE {$where}
GROUP BY asp.dispatch_area_id, da.name
ORDER BY case_count DESC
SQL;

        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        return array_map(
            static fn (array $row): CaseFlowOriginRow => new CaseFlowOriginRow(
                (int) $row['dispatch_area_id'],
                (string) $row['origin_name'],
                (int) $row['case_count'],
                (int) $row['emergency_count'],
            ),
            $rows,
        );
    }
}
