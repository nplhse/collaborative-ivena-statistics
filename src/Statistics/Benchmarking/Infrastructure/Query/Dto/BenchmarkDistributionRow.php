<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Infrastructure\Query\Dto;

final readonly class BenchmarkDistributionRow
{
    public function __construct(
        public string $dimension,
        public string $bucketKey,
        public ?string $bucketLabel,
        public int $primaryCount,
        public int $comparisonCount,
    ) {
    }
}
