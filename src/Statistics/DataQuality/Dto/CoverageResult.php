<?php

declare(strict_types=1);

namespace App\Statistics\DataQuality\Dto;

use App\Statistics\DataQuality\DataQualityLevel;

final readonly class CoverageResult
{
    public function __construct(
        public DataQualityLevel $level,
        public int $totalHospitals,
        public int $participatingHospitals,
        public float $coverageRatio,
        public float $coveragePercentage,
    ) {
    }
}
