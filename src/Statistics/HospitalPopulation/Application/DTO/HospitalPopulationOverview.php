<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application\DTO;

final readonly class HospitalPopulationOverview
{
    public function __construct(
        public int $totalHospitals,
        public int $participants,
        public float $coverage,
        public CoverageCrossTable $sizeByTierCrossTable,
        public CoverageCrossTable $urbanityByTierCrossTable,
    ) {
    }
}
