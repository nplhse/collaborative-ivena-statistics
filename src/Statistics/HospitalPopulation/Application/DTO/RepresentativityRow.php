<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application\DTO;

final readonly class RepresentativityRow
{
    public function __construct(
        public string $key,
        public string $label,
        public int $populationCount,
        public int $participantCount,
        public float $populationSharePercent,
        public float $participantSharePercent,
        public float $deltaPercentPoints,
    ) {
    }
}
