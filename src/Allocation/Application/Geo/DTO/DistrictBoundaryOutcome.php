<?php

declare(strict_types=1);

namespace App\Allocation\Application\Geo\DTO;

use App\Allocation\Domain\Enum\DistrictBoundaryFetchFailure;

/** @psalm-immutable */
final readonly class DistrictBoundaryOutcome
{
    /**
     * @param array<string, mixed>|null $geometry
     */
    private function __construct(
        public ?array $geometry = null,
        public ?string $kind = null,
        public ?string $provider = null,
        public ?string $licence = null,
        public ?string $attribution = null,
        public ?DistrictBoundaryFetchFailure $failure = null,
    ) {
    }

    /**
     * @param array<string, mixed> $geometry
     */
    public static function found(array $geometry, ?string $kind, string $provider, string $licence, string $attribution): self
    {
        return new self($geometry, $kind, $provider, $licence, $attribution);
    }

    public static function failed(DistrictBoundaryFetchFailure $failure): self
    {
        return new self(failure: $failure);
    }
}
