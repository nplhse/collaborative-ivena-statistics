<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsDrawerFilter;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\Insights\InsightPopulationFilter;
use App\Statistics\CaseFlow\Infrastructure\Query\Dto\CaseFlowOriginRow;
use Doctrine\DBAL\ArrayParameterType;
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
        ?int $originStateId = null,
        ?StatisticsDrawerFilter $drawerFilter = null,
        CaseFlowDispatchAreaMatch $dispatchAreaMatch = CaseFlowDispatchAreaMatch::Related,
        ?InsightPopulationFilter $population = null,
    ): array {
        if (CaseFlowSqlFilter::isImpossibleScope($scope, $originStateId)) {
            return [];
        }

        if ($population instanceof InsightPopulationFilter && $population->isEmpty()) {
            return [];
        }

        [$where, $params, $types] = CaseFlowSqlFilter::buildScopePeriodWhere(
            $from,
            $toExclusive,
            $scope,
            'asp',
            $originStateId,
            $drawerFilter,
            $dispatchAreaMatch,
        );

        if ($population instanceof InsightPopulationFilter) {
            $where .= ' AND '.$population->sqlInPredicate('subject_ids', 'asp');
            $params['subject_ids'] = $population->ids;
            $types['subject_ids'] = ArrayParameterType::INTEGER;
        }

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
