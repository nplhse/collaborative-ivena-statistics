<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Application\DTO;

final readonly class BenchmarkDistribution
{
    /**
     * @param list<BenchmarkDistributionBucket> $buckets
     */
    public function __construct(
        public BenchmarkMetricKey $dimension,
        public array $buckets,
    ) {
    }
}
