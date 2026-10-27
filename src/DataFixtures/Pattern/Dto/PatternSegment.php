<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Dto;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;

final readonly class PatternSegment
{
    public function __construct(
        public HospitalTier $hospitalTier,
        public HospitalLocation $hospitalLocation,
    ) {
    }
}
