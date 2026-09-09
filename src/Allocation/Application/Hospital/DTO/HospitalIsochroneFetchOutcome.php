<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\DTO;

/** @psalm-immutable */
final readonly class HospitalIsochroneFetchOutcome
{
    /**
     * @param array{type: string, features: list<array<string, mixed>>}|null $geojson
     */
    private function __construct(
        public bool $requestFailed,
        public bool $rateLimited,
        public ?array $geojson = null,
        public ?int $retryAfterSeconds = null,
    ) {
    }

    /**
     * @param array{type: string, features: list<array<string, mixed>>} $geojson
     */
    public static function success(array $geojson): self
    {
        return new self(requestFailed: false, rateLimited: false, geojson: $geojson);
    }

    public static function failed(): self
    {
        return new self(requestFailed: true, rateLimited: false);
    }

    public static function rateLimited(?int $retryAfterSeconds = null): self
    {
        return new self(requestFailed: false, rateLimited: true, retryAfterSeconds: $retryAfterSeconds);
    }
}
