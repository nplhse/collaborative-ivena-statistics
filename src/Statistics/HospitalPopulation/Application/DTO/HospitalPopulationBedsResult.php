<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application\DTO;

final readonly class HospitalPopulationBedsResult
{
    /**
     * @param list<BedsCategoryBoxPlotRow> $bedsBoxPlotByCareLevel
     * @param list<BedsCategoryBoxPlotRow> $bedsBoxPlotByLocation
     */
    public function __construct(
        public DescriptiveStats $bedsPopulation,
        public DescriptiveStats $bedsParticipants,
        public array $bedsBoxPlotByCareLevel,
        public array $bedsBoxPlotByLocation,
    ) {
    }
}
