<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application\DTO;

final readonly class HospitalPopulationAllocationsResult
{
    public function __construct(
        public AllocationBasisSummary $allocationBasis,
    ) {
    }
}
