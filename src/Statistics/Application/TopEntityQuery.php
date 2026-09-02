<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Infrastructure\Query\ProjectionTimeSeriesQuery;
use App\Statistics\Infrastructure\Query\ProjectionTopEntityQuery;

/**
 * @phpstan-type Row array{label: string, count: int, entityId: ?int}
 */
final readonly class TopEntityQuery
{
    public function __construct(
        private ProjectionTopEntityQuery $projectionTopEntityQuery,
        private ProjectionTimeSeriesQuery $timeSeriesQuery,
        private StatisticsScopeResolver $scopeResolver,
    ) {
    }

    /**
     * @return array{rows: list<Row>, totalAllocations: int}
     */
    public function fetch(
        StatisticsContext $context,
        int $limit,
        string $projectionJoinProperty,
        string $entityFqcn,
        ?int $totalAllocations = null,
        bool $requireJoinedEntity = false,
    ): array {
        $bounds = StatisticsPeriodResolver::resolve($context->filter);
        $scopeCriteria = $this->scopeResolver->resolveCriteria($context);

        $drawerFilter = $context->drawerFilter;

        $rows = $this->projectionTopEntityQuery->fetchTopAggregates(
            $bounds->from,
            $bounds->toExclusive,
            $scopeCriteria->hospitalIds,
            $limit,
            $projectionJoinProperty,
            $entityFqcn,
            $drawerFilter,
            $scopeCriteria->dispatchAreaId,
            $requireJoinedEntity,
        );

        $total = $totalAllocations ?? $this->timeSeriesQuery->countCreatedInPeriod(
            $bounds->from,
            $bounds->toExclusive,
            $scopeCriteria->hospitalIds,
            $drawerFilter,
            $scopeCriteria->dispatchAreaId,
            $requireJoinedEntity ? $projectionJoinProperty : null,
        );

        return [
            'rows' => $rows,
            'totalAllocations' => $total,
        ];
    }
}
