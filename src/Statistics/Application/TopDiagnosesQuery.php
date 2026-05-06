<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Infrastructure\Repository\AllocationStatsProjectionRepository;

/**
 * Most frequent diagnosis / indication labels (normalized, otherwise raw name) for the selected period and scope.
 *
 * @phpstan-type Row array{label: string, count: int}
 */
final readonly class TopDiagnosesQuery
{
    public function __construct(
        private AllocationStatsProjectionRepository $projectionRepository,
        private StatisticsScopeResolver $scopeResolver,
    ) {
    }

    /**
     * @return array{rows: list<Row>, totalAllocations: int}
     */
    public function fetch(StatisticsContext $context, int $limit): array
    {
        $bounds = StatisticsPeriodResolver::resolve($context->filter);
        $hospitalIds = $this->scopeResolver->hospitalIdsOrNull($context);

        $rows = $this->projectionRepository->fetchTopDiagnosisAggregates(
            $bounds->from,
            $bounds->toExclusive,
            $hospitalIds,
            $limit,
        );

        $total = $this->projectionRepository->countCreatedInPeriod($bounds->from, $bounds->toExclusive, $hospitalIds);

        return [
            'rows' => $rows,
            'totalAllocations' => $total,
        ];
    }

}
