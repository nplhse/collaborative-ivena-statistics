<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO;

/** @psalm-immutable */
final readonly class CatalogOrientationMap
{
    public function __construct(
        public bool $enabled,
        public ?string $highlightKey = null,
        public bool $showAllAreas = false,
        public ?float $markerLatitude = null,
        public ?float $markerLongitude = null,
        public ?string $markerLabel = null,
    ) {
    }

    public function hasMarker(): bool
    {
        return null !== $this->markerLatitude && null !== $this->markerLongitude;
    }

    public static function disabled(): self
    {
        return new self(enabled: false);
    }
}
