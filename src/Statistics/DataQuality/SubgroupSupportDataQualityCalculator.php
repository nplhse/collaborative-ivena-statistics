<?php

declare(strict_types=1);

namespace App\Statistics\DataQuality;

use App\Statistics\DataQuality\Dto\DataQualityHospitalSnapshot;
use App\Statistics\DataQuality\Dto\SubgroupCell;
use App\Statistics\DataQuality\Dto\SubgroupSupportResult;

final class SubgroupSupportDataQualityCalculator
{
    /**
     * @param list<DataQualityHospitalSnapshot> $population
     * @param list<int>                         $participantIds
     */
    public function calculate(array $population, array $participantIds): SubgroupSupportResult
    {
        $totalHospitals = \count($population);
        $participantIdSet = array_fill_keys($participantIds, true);

        $cellDefinitions = $this->cellDefinitions();
        $cells = [];
        $supportedPopulationCount = 0;
        $supportedCellsCount = 0;
        $relevantCellsCount = 0;
        $supportedRelevantCellsCount = 0;
        $weaklySupported = [];

        foreach ($cellDefinitions as $cellKey => $matcher) {
            $populationCount = 0;
            $participantCount = 0;

            foreach ($population as $hospital) {
                if (!$matcher($hospital)) {
                    continue;
                }

                ++$populationCount;
                if (isset($participantIdSet[$hospital->id])) {
                    ++$participantCount;
                }
            }

            if ($populationCount <= 0) {
                $cells[] = new SubgroupCell($cellKey, 0, 0, false);

                continue;
            }

            ++$relevantCellsCount;
            $supported = $participantCount >= $this->minParticipantsRequired($populationCount);
            $cell = new SubgroupCell($cellKey, $populationCount, $participantCount, $supported);
            $cells[] = $cell;

            if ($supported) {
                $supportedPopulationCount += $populationCount;
                ++$supportedCellsCount;
                ++$supportedRelevantCellsCount;
            } else {
                $weaklySupported[] = $cell;
            }
        }

        $supportedPopulationShare = $totalHospitals > 0
            ? $supportedPopulationCount / $totalHospitals
            : 0.0;

        $supportedRelevantCellShare = $relevantCellsCount > 0
            ? $supportedRelevantCellsCount / $relevantCellsCount
            : 0.0;

        $ratingShare = $relevantCellsCount < DataQualityThresholds::MIN_RELEVANT_SUBGROUP_CELLS_FOR_POPULATION_SHARE
            ? $supportedRelevantCellShare
            : $supportedPopulationShare;

        return new SubgroupSupportResult(
            DataQualityLevel::fromShareRatio($ratingShare),
            \count($cells),
            $relevantCellsCount,
            $supportedCellsCount,
            round($supportedPopulationShare, 4),
            round($supportedRelevantCellShare, 4),
            $cells,
            $weaklySupported,
        );
    }

    private function minParticipantsRequired(int $populationCount): int
    {
        return min(DataQualityThresholds::MIN_PARTICIPANTS_PER_CELL, $populationCount);
    }

    /**
     * @return array<string, callable(DataQualityHospitalSnapshot): bool>
     */
    private function cellDefinitions(): array
    {
        $cells = [];

        foreach (['Small', 'Medium', 'Large'] as $size) {
            foreach (['Basic', 'Extended', 'Full', 'unknown'] as $careLevel) {
                $key = sprintf('size_care:%s×%s', $size, $careLevel);
                $cells[$key] = static fn (DataQualityHospitalSnapshot $h): bool => $h->size === $size
                    && ($h->careLevel ?? 'unknown') === $careLevel;
            }
        }

        foreach (['Urban', 'Mixed', 'Rural'] as $urbanity) {
            foreach (['Basic', 'Extended', 'Full', 'unknown'] as $careLevel) {
                $key = sprintf('urbanity_care:%s×%s', $urbanity, $careLevel);
                $cells[$key] = static fn (DataQualityHospitalSnapshot $h): bool => $h->urbanity === $urbanity
                    && ($h->careLevel ?? 'unknown') === $careLevel;
            }
        }

        return $cells;
    }
}
