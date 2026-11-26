<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Application\Contract;

use App\Statistics\Application\DTO\StatisticsDrawerFilter;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkAggregationResult;

interface BenchmarkAggregationProviderInterface
{
    public function aggregate(
        StatisticsScopeCriteria $primaryScope,
        StatisticsPeriodBounds $primaryPeriod,
        StatisticsScopeCriteria $comparisonScope,
        StatisticsPeriodBounds $comparisonPeriod,
    ): BenchmarkAggregationResult;

    /**
     * Overview self-benchmark: reduced core counters + indication distribution only. A drawer filter applies to
     * both sides alike (monthly report, #1039).
     */
    public function aggregateForOverview(
        StatisticsScopeCriteria $primaryScope,
        StatisticsPeriodBounds $primaryPeriod,
        StatisticsScopeCriteria $comparisonScope,
        StatisticsPeriodBounds $comparisonPeriod,
        ?StatisticsDrawerFilter $drawerFilter = null,
    ): BenchmarkAggregationResult;
}
