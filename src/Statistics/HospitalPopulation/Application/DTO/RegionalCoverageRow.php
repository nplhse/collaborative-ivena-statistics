<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application\DTO;

final readonly class RegionalCoverageRow
{
    public function __construct(
        public int $stateId,
        public string $stateName,
        public int $dispatchAreaId,
        public string $dispatchAreaName,
        public int $population,
        public int $participants,
        public float $coverage,
    ) {
    }
}
