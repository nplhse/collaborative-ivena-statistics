<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application\DTO;

final readonly class HospitalPopulationKpis
{
    public function __construct(
        public int $totalHospitals,
        public int $participants,
        public float $coverage,
        public int $dispatchAreasTotal,
        public int $dispatchAreasRepresented,
    ) {
    }
}
