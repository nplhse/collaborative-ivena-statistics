<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application\DTO;

final readonly class AllocationCrossTableCell
{
    public function __construct(
        public int $totalAllocations,
        public int $hospitalCount,
        public ?float $meanPerHospital,
        public float $sharePercent,
    ) {
    }
}
