<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO;

/**
 * Aggregated headline figures for the hospital summary (overview period).
 *
 * @param array<string, int> $genderCounts  Keys: AllocationGender values (M, F, X)
 * @param array<int, int>    $urgencyCounts Keys: AllocationUrgency values (1, 2, 3)
 */
final readonly class HospitalSummaryData
{
    /**
     * @param array<string, int> $genderCounts
     * @param array<int, int>    $urgencyCounts
     */
    public function __construct(
        public int $totalAllocationsInPeriod,
        public int $userHospitalsAllocationsInPeriod,
        public array $genderCounts,
        public array $urgencyCounts,
        public bool $usedUnscopedFallback = false,
    ) {
    }
}
