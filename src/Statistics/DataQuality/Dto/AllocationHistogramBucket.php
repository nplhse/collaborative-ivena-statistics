<?php

declare(strict_types=1);

namespace App\Statistics\DataQuality\Dto;

final readonly class AllocationHistogramBucket
{
    public function __construct(
        public string $label,
        public int $hospitalCount,
        public bool $meetsVolumeThreshold,
    ) {
    }
}
