<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO;

use App\Allocation\Application\Explore\Catalog\IsochroneTravelBand;

/** @psalm-immutable */
final readonly class CatalogOrientationMap
{
    /**
     * @param array{type: string, features: list<array<string, mixed>>}|null $isochronesGeoJson
     * @param list<array{lat: float, lng: float, label: string}>             $contextMarkers
     */
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
        public ?array $isochronesGeoJson = null,
        public ?int $recordedTravelMinutes = null,
        public array $contextMarkers = [],
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

    public function hasIsochrones(): bool
    {
        return null !== $this->isochronesGeoJson
            && isset($this->isochronesGeoJson['features'])
            && [] !== $this->isochronesGeoJson['features'];
    }

    public function hasContextMarkers(): bool
    {
        return [] !== $this->contextMarkers;
    }

    public function recordedTravelExceedsIsochrones(): bool
    {
        return IsochroneTravelBand::exceedsMaximum($this->recordedTravelMinutes);
    }

    public static function disabled(): self
    {
        return new self(enabled: false);
    }
}
