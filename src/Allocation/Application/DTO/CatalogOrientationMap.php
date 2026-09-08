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
        public ?string $destinationHighlightKey = null,
        public bool $showRoute = false,
        public ?string $districtLabel = null,
    ) {
    }

    public function hasMarker(): bool
    {
        return null !== $this->markerLatitude && null !== $this->markerLongitude;
    }

    public function hasDestinationHighlight(): bool
    {
        return null !== $this->destinationHighlightKey && '' !== $this->destinationHighlightKey;
    }

    public function hasDistrictLabel(): bool
    {
        return null !== $this->districtLabel && '' !== $this->districtLabel;
    }

    public static function disabled(): self
    {
        return new self(enabled: false);
    }
}
