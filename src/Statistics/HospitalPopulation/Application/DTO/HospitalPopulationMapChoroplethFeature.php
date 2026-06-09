<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application\DTO;

final readonly class HospitalPopulationMapChoroplethFeature
{
    public function __construct(
        public int $dispatchAreaId,
        public string $landkreis,
        public string $geoFeatureKey,
        public int $population,
        public int $participants,
        public float $coverage,
    ) {
    }
}
