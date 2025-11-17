<?php

namespace App\Model;

final class HospitalCompositionStats
{
    /**
     * @param HospitalGroupStats[] $byTier
     * @param HospitalGroupStats[] $byLocation
     * @param HospitalGroupStats[] $bySize
     */
    public function __construct(
        public int $totalHospitals,
        public int $totalParticipantHospitals,
        public int $totalAllocations,
        public int $totalParticipantAllocations,
        public array $byTier,
        public array $byLocation,
        public array $bySize,
    ) {
    }
}
