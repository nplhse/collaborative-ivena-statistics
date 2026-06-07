<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Application\DTO;

final readonly class BenchmarkDistributionBucket
{
    public function __construct(
        public string $key,
        public string $label,
        public int $primaryCount,
        public int $comparisonCount,
        public float $primaryShare,
        public float $comparisonShare,
        public float $ratio,
    ) {
    }
}
