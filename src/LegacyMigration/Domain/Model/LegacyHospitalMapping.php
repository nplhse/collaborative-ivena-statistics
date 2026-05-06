<?php

declare(strict_types=1);

namespace App\LegacyMigration\Domain\Model;

final readonly class LegacyHospitalMapping
{
    public function __construct(
        public int $legacyHospitalId,
        public int $newHospitalId,
        public string $legacyName,
        public string $matchedName,
        public float $matchScore,
    ) {
    }
}

