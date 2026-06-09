<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Statistics\HospitalPopulation\Application\DTO\BedsBoxPlotBreakdown;
use App\Statistics\HospitalPopulation\Application\DTO\BedsCategoryBoxPlotRow;
use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationSnapshot;

final readonly class BedsBoxPlotBuilder
{
    public function __construct(
        private DescriptiveStatisticsCalculator $descriptiveStatisticsCalculator,
    ) {
    }

    /**
     * @param list<HospitalPopulationSnapshot> $snapshots
     */
    public function build(array $snapshots): BedsBoxPlotBreakdown
    {
        return new BedsBoxPlotBreakdown(
            byCareLevel: $this->buildByCareLevel($snapshots),
            byLocation: $this->buildByLocation($snapshots),
        );
    }

    /**
     * @param list<HospitalPopulationSnapshot> $snapshots
     *
     * @return list<BedsCategoryBoxPlotRow>
     */
    private function buildByCareLevel(array $snapshots): array
    {
        $rows = [];

        foreach (HospitalTier::cases() as $careLevel) {
            $populationBeds = [];
            $participantBeds = [];

            foreach ($snapshots as $snapshot) {
                if ($snapshot->careLevel !== $careLevel) {
                    continue;
                }

                $populationBeds[] = $snapshot->beds;
                if ($snapshot->isParticipating) {
                    $participantBeds[] = $snapshot->beds;
                }
            }

            $rows[] = new BedsCategoryBoxPlotRow(
                key: $careLevel->value,
                label: $careLevel->value,
                population: $this->descriptiveStatisticsCalculator->calculate($populationBeds),
                participants: $this->descriptiveStatisticsCalculator->calculate($participantBeds),
            );
        }

        return $rows;
    }

    /**
     * @param list<HospitalPopulationSnapshot> $snapshots
     *
     * @return list<BedsCategoryBoxPlotRow>
     */
    private function buildByLocation(array $snapshots): array
    {
        $rows = [];

        foreach (HospitalLocation::cases() as $location) {
            $populationBeds = [];
            $participantBeds = [];

            foreach ($snapshots as $snapshot) {
                if ($snapshot->urbanity !== $location) {
                    continue;
                }

                $populationBeds[] = $snapshot->beds;
                if ($snapshot->isParticipating) {
                    $participantBeds[] = $snapshot->beds;
                }
            }

            $rows[] = new BedsCategoryBoxPlotRow(
                key: $location->value,
                label: $location->value,
                population: $this->descriptiveStatisticsCalculator->calculate($populationBeds),
                participants: $this->descriptiveStatisticsCalculator->calculate($participantBeds),
            );
        }

        return $rows;
    }
}
