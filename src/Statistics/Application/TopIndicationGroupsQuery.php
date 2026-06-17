<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Infrastructure\Query\IndicationGroup\ProjectionTopIndicationGroupsQuery;
use App\Statistics\Infrastructure\Query\ProjectionTimeSeriesQuery;

/**
 * @phpstan-type Row array{groupId: int, label: string, count: int}
 */
final readonly class TopIndicationGroupsQuery
{
    public function __construct(
        private ProjectionTopIndicationGroupsQuery $groupsQuery,
        private ProjectionTimeSeriesQuery $timeSeriesQuery,
        private StatisticsScopeResolver $scopeResolver,
    ) {
    }

    /**
     * @return array{rows: list<Row>, totalAllocations: int}
     */
    public function fetch(StatisticsContext $context, int $limit): array
    {
        $bounds = StatisticsPeriodResolver::resolve($context->filter);
        $scopeCriteria = $this->scopeResolver->resolveCriteria($context);

        $rows = $this->groupsQuery->fetchTopGroupAggregates(
            $bounds->from,
            $bounds->toExclusive,
            $scopeCriteria->hospitalIds,
            $limit,
        );

        $total = $this->timeSeriesQuery->countCreatedInPeriod($bounds->from, $bounds->toExclusive, $scopeCriteria->hospitalIds);

        return [
            'rows' => $rows,
            'totalAllocations' => $total,
        ];
    }
}
