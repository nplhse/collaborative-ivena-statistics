<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\DTO;

/** @psalm-immutable */
final readonly class HospitalGeocodeOutcome
{
    private function __construct(
        public bool $unusableMatch,
        public bool $requestFailed,
        public bool $rateLimited,
        public ?HospitalGeocodeMatch $match = null,
        public ?int $retryAfterSeconds = null,
    ) {
    }

    public static function match(HospitalGeocodeMatch $match): self
    {
        return new self(unusableMatch: false, requestFailed: false, rateLimited: false, match: $match);
    }

    public static function unusableMatch(): self
    {
        return new self(unusableMatch: true, requestFailed: false, rateLimited: false);
    }

    public static function failed(): self
    {
        return new self(unusableMatch: false, requestFailed: true, rateLimited: false);
    }

    public static function rateLimited(?int $retryAfterSeconds = null): self
    {
        return new self(unusableMatch: false, requestFailed: false, rateLimited: true, retryAfterSeconds: $retryAfterSeconds);
    }
}
