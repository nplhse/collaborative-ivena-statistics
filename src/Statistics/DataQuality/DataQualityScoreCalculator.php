<?php

declare(strict_types=1);

namespace App\Statistics\DataQuality;

use App\Statistics\DataQuality\Dto\AllocationVolumeResult;
use App\Statistics\DataQuality\Dto\CoverageResult;
use App\Statistics\DataQuality\Dto\RepresentativenessResult;
use App\Statistics\DataQuality\Dto\SubgroupSupportResult;

final class DataQualityScoreCalculator
{
    public function calculateOverall(
        CoverageResult $coverage,
        RepresentativenessResult $representativeness,
        SubgroupSupportResult $subgroupSupport,
        AllocationVolumeResult $allocationVolume,
    ): DataQualityLevel {
        $levels = [
            $coverage->level,
            $representativeness->level,
            $subgroupSupport->level,
            $allocationVolume->level,
        ];

        $averageScore = array_sum(array_map(
            static fn (DataQualityLevel $level): int => $level->score(),
            $levels,
        )) / \count($levels);

        $overall = DataQualityLevel::fromScore($averageScore);

        foreach ($levels as $level) {
            if (DataQualityLevel::Low === $level && DataQualityLevel::High === $overall) {
                return DataQualityLevel::Medium;
            }
        }

        return $overall;
    }
}
