<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application\DTO;

final readonly class DistributionSummaryRow
{
    public function __construct(
        public string $key,
        public string $label,
        public int $population,
        public int $participants,
        public float $coverage,
        public float $populationSharePercent,
        public float $participantSharePercent,
        public float $deltaPercentPoints,
    ) {
    }
}
