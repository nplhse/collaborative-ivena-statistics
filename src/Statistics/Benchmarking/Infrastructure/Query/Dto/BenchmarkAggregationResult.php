<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Infrastructure\Query\Dto;

final readonly class BenchmarkAggregationResult
{
    /**
     * @param list<BenchmarkDistributionRow> $distributionRows
     */
    public function __construct(
        public BenchmarkSideCounts $primary,
        public BenchmarkSideCounts $comparison,
        public array $distributionRows,
    ) {
    }

    public static function empty(): self
    {
        return new self(BenchmarkSideCounts::empty(), BenchmarkSideCounts::empty(), []);
    }

    public function hasEmptyPrimaryScope(): bool
    {
        return 0 === $this->primary->total && 0 === $this->comparison->total;
    }
}
