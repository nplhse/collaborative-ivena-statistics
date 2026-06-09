<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application\DTO;

final readonly class DescriptiveStats
{
    public function __construct(
        public int $count,
        public ?int $sum,
        public ?int $minimum,
        public ?int $maximum,
        public ?float $mean,
        public ?float $median,
        public ?float $standardDeviation,
        public ?float $p10,
        public ?float $p25,
        public ?float $p75,
        public ?float $p95,
    ) {
    }
}
