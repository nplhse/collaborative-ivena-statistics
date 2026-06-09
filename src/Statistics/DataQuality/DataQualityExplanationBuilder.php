<?php

declare(strict_types=1);

namespace App\Statistics\DataQuality;

use App\Statistics\DataQuality\Dto\AllocationVolumeResult;
use App\Statistics\DataQuality\Dto\CoverageResult;
use App\Statistics\DataQuality\Dto\RepresentativenessResult;
use App\Statistics\DataQuality\Dto\SubgroupSupportResult;

final class DataQualityExplanationBuilder
{
    /**
     * @return array{0: string, 1: array<string, int|float|string>}
     */
    public function build(
        DataQualityLevel $overallLevel,
        CoverageResult $coverage,
        RepresentativenessResult $representativeness,
        SubgroupSupportResult $subgroupSupport,
        AllocationVolumeResult $allocationVolume,
    ): array {
        if (DataQualityLevel::High === $overallLevel) {
            return ['stats.data_quality.explanation.high', []];
        }

        $lowDimensions = [];
        if (DataQualityLevel::Low === $coverage->level) {
            $lowDimensions[] = 'coverage';
        }
        if (DataQualityLevel::Low === $representativeness->level) {
            $lowDimensions[] = 'representativeness';
        }
        if (DataQualityLevel::Low === $subgroupSupport->level) {
            $lowDimensions[] = 'subgroup';
        }
        if (DataQualityLevel::Low === $allocationVolume->level) {
            $lowDimensions[] = 'allocation';
        }

        if (\in_array('coverage', $lowDimensions, true) && \in_array('representativeness', $lowDimensions, true)) {
            return ['stats.data_quality.explanation.low_coverage_and_representativeness', []];
        }

        if (\in_array('subgroup', $lowDimensions, true)) {
            return ['stats.data_quality.explanation.weak_subgroups', [
                'weakCount' => \count($subgroupSupport->weaklySupportedCells),
            ]];
        }

        if (\in_array('coverage', $lowDimensions, true)) {
            return ['stats.data_quality.explanation.low_coverage', [
                'percentage' => $coverage->coveragePercentage,
            ]];
        }

        if (\in_array('representativeness', $lowDimensions, true)) {
            return ['stats.data_quality.explanation.low_representativeness', [
                'difference' => $representativeness->averageDifference,
            ]];
        }

        if (\in_array('allocation', $lowDimensions, true)) {
            return ['stats.data_quality.explanation.low_allocation_volume', [
                'share' => round($allocationVolume->shareHospitalsWithSufficientAllocations * 100.0, 0),
                'threshold' => $allocationVolume->minAllocationsPerHospital,
            ]];
        }

        return ['stats.data_quality.explanation.medium', []];
    }
}
