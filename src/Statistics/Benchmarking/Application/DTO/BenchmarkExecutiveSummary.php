<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Application\DTO;

final readonly class BenchmarkExecutiveSummary
{
    /**
     * @param list<BenchmarkInsight> $above
     * @param list<BenchmarkInsight> $neutral
     * @param list<BenchmarkInsight> $below
     */
    public function __construct(
        public array $above,
        public array $neutral,
        public array $below,
    ) {
    }
}
