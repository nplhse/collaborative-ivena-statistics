<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\DTO;

/** @psalm-immutable */
final readonly class HospitalGeocodeMatch
{
    public function __construct(
        public float $latitude,
        public float $longitude,
        public string $label,
        public string $layer,
    ) {
    }
}
