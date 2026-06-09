<?php

declare(strict_types=1);

namespace App\Statistics\DataQuality\Dto;

use App\Statistics\DataQuality\DataQualityLevel;

final readonly class AllocationVolumeResult
{
    /**
     * @param list<array{id: int, name: string, count: int}> $hospitalsBelowThreshold
     * @param list<AllocationHistogramBucket>                $histogram
     */
    public function __construct(
        public DataQualityLevel $level,
        public int $totalAllocations,
        public float $meanAllocationsPerHospital,
        public float $medianAllocationsPerHospital,
        public int $p25,
        public int $p75,
        public int $p95,
        public int $max,
        public float $shareHospitalsWithSufficientAllocations,
        public int $minAllocationsPerHospital,
        public int $participantHospitalCount,
        public array $hospitalsBelowThreshold,
        public array $histogram,
    ) {
    }
}
