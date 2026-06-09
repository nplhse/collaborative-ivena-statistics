<?php

declare(strict_types=1);

namespace App\Statistics\DataQuality\Dto;

use App\Statistics\DataQuality\DataQualityLevel;

final readonly class RepresentativenessResult
{
    /**
     * @param list<RepresentativenessDimensionDetail> $dimensions
     */
    public function __construct(
        public DataQualityLevel $level,
        public float $averageDifference,
        public array $dimensions,
    ) {
    }
}
