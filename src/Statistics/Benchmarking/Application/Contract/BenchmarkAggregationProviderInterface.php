<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Application\Contract;

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
}
