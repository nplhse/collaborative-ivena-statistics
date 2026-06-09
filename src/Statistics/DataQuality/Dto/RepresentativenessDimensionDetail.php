<?php

declare(strict_types=1);

namespace App\Statistics\DataQuality\Dto;

use App\Statistics\DataQuality\DataQualityLevel;

final readonly class RepresentativenessDimensionDetail
{
    /**
     * @param list<string> $topDeviations
     */
    public function __construct(
        public string $dimensionKey,
        public float $difference,
        public DataQualityLevel $level,
        public array $topDeviations,
        public bool $limitedReliability,
    ) {
    }
}
