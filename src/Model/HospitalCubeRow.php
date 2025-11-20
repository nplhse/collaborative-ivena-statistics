<?php

namespace App\Model;

final class HospitalCubeRow
{
    public function __construct(
        public string $tier,
        public string $location,
        public string $size,
        public string $state,
        public string $dispatchArea,
        public bool $isParticipating,
        public int $beds,
        public int $allocationCount,
    ) {
    }
}
