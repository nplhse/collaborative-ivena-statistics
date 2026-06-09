<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application\DTO;

final readonly class HospitalPopulationMapMarker
{
    public function __construct(
        public int $id,
        public string $name,
        public float $latitude,
        public float $longitude,
        public int $beds,
        public ?string $careLevel,
        public string $location,
        public bool $isParticipating,
    ) {
    }
}
