<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\DTO;

/** @psalm-immutable */
final readonly class HospitalGeocodeOutcome
{
    private function __construct(
        public bool $unusableMatch,
        public bool $requestFailed,
        public ?HospitalGeocodeMatch $match = null,
    ) {
    }

    public static function match(HospitalGeocodeMatch $match): self
    {
        return new self(unusableMatch: false, requestFailed: false, match: $match);
    }

    public static function unusableMatch(): self
    {
        return new self(unusableMatch: true, requestFailed: false);
    }

    public static function failed(): self
    {
        return new self(unusableMatch: false, requestFailed: true);
    }
}
