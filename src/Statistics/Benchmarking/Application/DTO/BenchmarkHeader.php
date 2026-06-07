<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Application\DTO;

final readonly class BenchmarkHeader
{
    public function __construct(
        public string $primaryScopeLabel,
        public string $comparisonScopeLabel,
        public string $primaryPeriodLabel,
        public string $comparisonPeriodLabel,
        public int $primaryTotal,
        public int $comparisonTotal,
    ) {
    }
}
