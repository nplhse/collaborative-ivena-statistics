<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application\DTO;

final readonly class BedsBoxPlotBreakdown
{
    /**
     * @param list<BedsCategoryBoxPlotRow> $byCareLevel
     * @param list<BedsCategoryBoxPlotRow> $byLocation
     */
    public function __construct(
        public array $byCareLevel,
        public array $byLocation,
    ) {
    }
}
