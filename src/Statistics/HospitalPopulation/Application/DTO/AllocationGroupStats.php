<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application\DTO;

final readonly class AllocationGroupStats
{
    public function __construct(
        public string $key,
        public string $label,
        public int $totalAllocations,
        public float $sharePercent,
        public int $hospitalCount,
        public ?float $meanPerHospital,
        public ?float $medianPerHospital,
    ) {
    }
}
