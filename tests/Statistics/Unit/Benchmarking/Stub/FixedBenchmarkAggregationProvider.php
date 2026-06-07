<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Benchmarking\Stub;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Benchmarking\Application\Contract\BenchmarkAggregationProviderInterface;
use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkAggregationResult;

final readonly class FixedBenchmarkAggregationProvider implements BenchmarkAggregationProviderInterface
{
    public function __construct(
        private BenchmarkAggregationResult $result,
    ) {
    }

    #[\Override]
    public function aggregate(
        StatisticsScopeCriteria $primaryScope,
        StatisticsPeriodBounds $primaryPeriod,
        StatisticsScopeCriteria $comparisonScope,
        StatisticsPeriodBounds $comparisonPeriod,
    ): BenchmarkAggregationResult {
        return $this->result;
    }
}
