<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application\DTO;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;

final readonly class HospitalPopulationSnapshot
{
    public function __construct(
        public int $id,
        public string $name,
        public int $stateId,
        public string $stateName,
        public int $dispatchAreaId,
        public string $dispatchAreaName,
        public ?float $latitude,
        public ?float $longitude,
        public int $beds,
        public HospitalSize $size,
        public ?HospitalTier $careLevel,
        public HospitalLocation $urbanity,
        public bool $hasAllocations,
        public bool $isParticipating,
        public int $allocationCount = 0,
    ) {
    }

    public function isParticipant(): bool
    {
        return $this->isParticipating;
    }
}
