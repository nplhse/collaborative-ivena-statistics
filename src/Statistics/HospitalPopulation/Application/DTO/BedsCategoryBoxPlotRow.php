<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application\DTO;

final readonly class BedsCategoryBoxPlotRow
{
    public function __construct(
        public string $key,
        public string $label,
        public DescriptiveStats $population,
        public DescriptiveStats $participants,
    ) {
    }
}
