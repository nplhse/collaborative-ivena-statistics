<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Infrastructure\Query\ProjectionDiagnosisQuery;
use App\Statistics\Infrastructure\Query\ProjectionTimeSeriesQuery;

/**
 * Most frequent diagnosis / indication labels (normalized, otherwise raw name) for the selected period and scope.
 *
 * @phpstan-type Row array{label: string, count: int, indicationId: ?int}
 */
final readonly class TopDiagnosesQuery
{
    public function __construct(
        private ProjectionDiagnosisQuery $diagnosisQuery,
        private ProjectionTimeSeriesQuery $timeSeriesQuery,
        private StatisticsScopeResolver $scopeResolver,
    ) {
    }

    /**
     * @return array{rows: list<Row>, totalAllocations: int}
     */
    public function fetch(StatisticsContext $context, int $limit, ?int $totalAllocations = null): array
    {
        $bounds = StatisticsPeriodResolver::resolve($context->filter);
        $scopeCriteria = $this->scopeResolver->resolveCriteria($context);

        $rows = $this->diagnosisQuery->fetchTopDiagnosisAggregates(
            $bounds->from,
            $bounds->toExclusive,
            $scopeCriteria->hospitalIds,
            $limit,
        );

        $total = $totalAllocations ?? $this->timeSeriesQuery->countCreatedInPeriod(
            $bounds->from,
            $bounds->toExclusive,
            $scopeCriteria->hospitalIds,
        );

        return [
            'rows' => $rows,
            'totalAllocations' => $total,
        ];
    }
}
