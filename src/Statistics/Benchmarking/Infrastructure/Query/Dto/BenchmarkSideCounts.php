<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Infrastructure\Query\Dto;

final readonly class BenchmarkSideCounts
{
    public function __construct(
        public int $total,
        public int $withPhysician,
        public int $resus,
        public int $cathlab,
        public int $cpr,
        public int $ventilated,
        public int $shock,
        public int $pregnant,
        public int $workAccident,
        public int $infectious,
        public int $urgencyEmergency,
        public int $urgencyInpatient,
        public int $urgencyOutpatient,
        public int $nightDaytime,
        public int $weekend,
        public int $age80Plus,
        public int $male,
        public int $female,
        public int $genderOther,
        public ?float $medianAge,
        public ?float $medianTransportMinutes,
        public ?float $meanTransportMinutes,
    ) {
    }

    public static function empty(): self
    {
        return new self(
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            null,
            null,
            null,
        );
    }
}
