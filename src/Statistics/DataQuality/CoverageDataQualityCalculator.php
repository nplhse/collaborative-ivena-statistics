<?php

declare(strict_types=1);

namespace App\Statistics\DataQuality;

use App\Statistics\DataQuality\Dto\CoverageResult;

final class CoverageDataQualityCalculator
{
    public function calculate(int $totalHospitals, int $participatingHospitals): CoverageResult
    {
        $coverageRatio = $totalHospitals > 0
            ? (float) $participatingHospitals / (float) $totalHospitals
            : 0.0;

        return new CoverageResult(
            DataQualityLevel::fromCoverageRatio($coverageRatio),
            $totalHospitals,
            $participatingHospitals,
            $coverageRatio,
            round($coverageRatio * 100.0, 1),
        );
    }
}
