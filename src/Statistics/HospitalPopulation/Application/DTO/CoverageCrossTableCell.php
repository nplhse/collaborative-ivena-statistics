<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application\DTO;

final readonly class CoverageCrossTableCell
{
    public function __construct(
        public int $population,
        public int $participants,
        public float $coverage,
    ) {
    }
}
