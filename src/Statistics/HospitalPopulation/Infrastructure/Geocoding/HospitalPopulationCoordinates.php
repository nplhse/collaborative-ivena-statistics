<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Infrastructure\Geocoding;

final readonly class HospitalPopulationCoordinates
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {
    }
}
