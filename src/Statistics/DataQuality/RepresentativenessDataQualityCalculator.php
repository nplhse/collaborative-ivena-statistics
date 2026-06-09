<?php

declare(strict_types=1);

namespace App\Statistics\DataQuality;

use App\Statistics\DataQuality\Dto\DataQualityHospitalSnapshot;
use App\Statistics\DataQuality\Dto\RepresentativenessDimensionDetail;
use App\Statistics\DataQuality\Dto\RepresentativenessResult;

final class RepresentativenessDataQualityCalculator
{
    private const string DIMENSION_SIZE = 'size';
    private const string DIMENSION_CARE_LEVEL = 'care_level';
    private const string DIMENSION_URBANITY = 'urbanity';
    private const string DIMENSION_LANDKREIS = 'landkreis';

    /**
     * @param list<DataQualityHospitalSnapshot> $population
     * @param list<int>                         $participantIds
     */
    public function calculate(array $population, array $participantIds): RepresentativenessResult
    {
        $participantIdSet = array_fill_keys($participantIds, true);
        $participants = array_values(array_filter(
            $population,
            static fn (DataQualityHospitalSnapshot $hospital): bool => isset($participantIdSet[$hospital->id]),
        ));

        $dimensions = [
            self::DIMENSION_SIZE,
            self::DIMENSION_CARE_LEVEL,
            self::DIMENSION_URBANITY,
            self::DIMENSION_LANDKREIS,
        ];

        $details = [];
        $scoreSum = 0;

        foreach ($dimensions as $dimensionKey) {
            $detail = $this->calculateDimension(
                $dimensionKey,
                $population,
                $participants,
                \count($population),
            );
            $details[] = $detail;
            $scoreSum += $detail->level->score();
        }

        $averageScore = $scoreSum / \count($details);
        $level = DataQualityLevel::fromScore($averageScore);

        foreach ($details as $detail) {
            if (DataQualityLevel::High !== $detail->level && DataQualityLevel::High === $level) {
                $level = DataQualityLevel::Medium;
                break;
            }
        }

        return new RepresentativenessResult(
            $level,
            $this->averageDifference($details),
            $details,
        );
    }

    /**
     * @param list<DataQualityHospitalSnapshot> $population
     * @param list<DataQualityHospitalSnapshot> $participants
     */
    private function calculateDimension(
        string $dimensionKey,
        array $population,
        array $participants,
        int $totalPopulation,
    ): RepresentativenessDimensionDetail {
        $populationShares = $this->categoryShares($population, $dimensionKey, $totalPopulation);
        $participantTotal = \count($participants);
        $participantShares = $this->categoryShares($participants, $dimensionKey, $participantTotal);

        $categories = array_unique(array_merge(array_keys($populationShares), array_keys($participantShares)));
        $difference = 0.0;
        $deviations = [];

        foreach ($categories as $category) {
            $popShare = $populationShares[$category] ?? 0.0;
            $partShare = $participantShares[$category] ?? 0.0;
            $difference += abs($popShare - $partShare);
            $deviations[$category] = ($partShare - $popShare) * 100.0;
        }

        $totalAbsoluteDifference = 0.5 * $difference;

        return new RepresentativenessDimensionDetail(
            $dimensionKey,
            round($totalAbsoluteDifference, 4),
            DataQualityLevel::fromRepresentativenessDifference($totalAbsoluteDifference),
            $this->topDeviations($deviations, $dimensionKey),
            $totalPopulation < DataQualityThresholds::MIN_POPULATION_FOR_DIMENSIONS,
        );
    }

    /**
     * @param list<DataQualityHospitalSnapshot> $hospitals
     *
     * @return array<string, float>
     */
    private function categoryShares(array $hospitals, string $dimensionKey, int $total): array
    {
        if ($total <= 0) {
            return [];
        }

        $counts = [];
        foreach ($hospitals as $hospital) {
            $category = $this->categoryValue($hospital, $dimensionKey);
            $counts[$category] = ($counts[$category] ?? 0) + 1;
        }

        $shares = [];
        foreach ($counts as $category => $count) {
            $shares[$category] = $count / $total;
        }

        return $shares;
    }

    private function categoryValue(DataQualityHospitalSnapshot $hospital, string $dimensionKey): string
    {
        return match ($dimensionKey) {
            self::DIMENSION_SIZE => $hospital->size,
            self::DIMENSION_CARE_LEVEL => $hospital->careLevel ?? 'unknown',
            self::DIMENSION_URBANITY => $hospital->urbanity,
            self::DIMENSION_LANDKREIS => $hospital->landkreis,
            default => 'unknown',
        };
    }

    /**
     * @param array<string, float> $deviationsPercentagePoints
     *
     * @return list<string>
     */
    private function topDeviations(array $deviationsPercentagePoints, string $dimensionKey): array
    {
        if ([] === $deviationsPercentagePoints) {
            return [];
        }

        $maxCategory = null;
        $maxValue = -INF;
        $minCategory = null;
        $minValue = INF;

        foreach ($deviationsPercentagePoints as $category => $value) {
            if ($value > $maxValue) {
                $maxValue = $value;
                $maxCategory = $category;
            }
            if ($value < $minValue) {
                $minValue = $value;
                $minCategory = $category;
            }
        }

        $highlights = [];
        if (null !== $maxCategory && abs($maxValue) >= 1.0) {
            $highlights[] = sprintf(
                '%s %s: +%d percentage points',
                $maxCategory,
                $dimensionKey,
                (int) round($maxValue),
            );
        }
        if (null !== $minCategory && abs($minValue) >= 1.0 && $minCategory !== $maxCategory) {
            $highlights[] = sprintf(
                '%s %s: %d percentage points',
                $minCategory,
                $dimensionKey,
                (int) round($minValue),
            );
        }

        return $highlights;
    }

    /**
     * @param list<RepresentativenessDimensionDetail> $details
     */
    private function averageDifference(array $details): float
    {
        if ([] === $details) {
            return 1.0;
        }

        $sum = 0.0;
        foreach ($details as $detail) {
            $sum += $detail->difference;
        }

        return round($sum / (float) \count($details), 2);
    }
}
