<?php

namespace App\Model;

final class HospitalGroupStats
{
    public function __construct(
        public string $groupKey,
        public string $groupLabel,
        public int $hospitalCount,
        public float $hospitalShare,
        public int $participantHospitalCount,
        public float $participantHospitalShare,
        public ?float $avgBeds,
        public ?float $sdBeds,
        public ?float $varBeds,
        public int $allocationCount,
        public float $allocationShare,
    ) {
    }
}
