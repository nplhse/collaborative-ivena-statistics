<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application\DTO;

final readonly class AllocationBasisSummary
{
    /**
     * @param list<AllocationGroupStats> $bySize
     * @param list<AllocationGroupStats> $byTier
     * @param list<AllocationGroupStats> $byLocation
     */
    public function __construct(
        public array $bySize,
        public array $byTier,
        public array $byLocation,
        public AllocationCrossTable $sizeByTierCrossTable,
        public AllocationCrossTable $locationByTierCrossTable,
    ) {
    }
}
