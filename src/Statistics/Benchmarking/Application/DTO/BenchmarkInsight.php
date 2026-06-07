<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Application\DTO;

final readonly class BenchmarkInsight
{
    public function __construct(
        public string $id,
        public BenchmarkInsightDirection $direction,
        public BenchmarkInsightSeverity $severity,
        public string $translationKey,
        public float $ratio,
        public float $primaryDisplay,
        public float $comparisonDisplay,
        public int $sortScore,
    ) {
    }
}
