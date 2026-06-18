<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview\Dto;

final readonly class OverviewBenchmarkDeviation
{
    public function __construct(
        public string $label,
        public float $ratio,
        public string $direction,
        public ?string $url,
    ) {
    }
}
