<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\Catalog;

use App\Allocation\Application\Contracts\DispatchAreaGeoIndexInterface;
use App\Allocation\Application\Contracts\HospitalIsochroneProviderInterface;
use App\Allocation\Application\Contracts\HospitalLookupInterface;
use App\Allocation\Application\DTO\CatalogOrientationMap;
use App\Allocation\Application\Geo\DTO\DispatchAreaGeoSnapshot;
use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\SecondaryTransport;

/**
 * Resolves the dispatch-area orientation map of Explore catalog detail pages from the shapes in the database.
 */
final readonly class CatalogOrientationMapFactory
{
    public function __construct(
        private DispatchAreaGeoIndexInterface $geoIndex,
        private HospitalIsochroneProviderInterface $isochroneProvider,
        private HospitalLookupInterface $hospitalLookup,
    ) {
    }

    public function forDispatchArea(?int $dispatchAreaId): CatalogOrientationMap
    {
        $key = $this->shapeKey($dispatchAreaId);
        if (null === $key) {
            return CatalogOrientationMap::disabled();
        }

        return new CatalogOrientationMap(enabled: true, highlightKey: $key);
    }

    public function forState(?string $name): CatalogOrientationMap
    {
        if (null === $name || '' === trim($name)) {
            return CatalogOrientationMap::disabled();
        }

        $state = $this->geoIndex->snapshot()->stateWithShapes($name);
        if (null === $state) {
            return CatalogOrientationMap::disabled();
        }

        return new CatalogOrientationMap(enabled: true, showAllAreas: true, stateName: $state);
    }

    public function forHospital(
        ?int $dispatchAreaId,
        ?float $latitude,
        ?float $longitude,
        ?string $markerLabel = null,
    ): CatalogOrientationMap {
        $areaMap = $this->forDispatchArea($dispatchAreaId);
        if (!$areaMap->enabled) {
            return CatalogOrientationMap::disabled();
        }

        $hasMarker = null !== $latitude && null !== $longitude;

        return new CatalogOrientationMap(
            enabled: true,
            highlightKey: $areaMap->highlightKey,
            markerLatitude: $hasMarker ? $latitude : null,
            markerLongitude: $hasMarker ? $longitude : null,
            markerLabel: $hasMarker ? $markerLabel : null,
        );
    }

    public function forAllocation(Allocation $allocation): CatalogOrientationMap
    {
        $origin = $allocation->getDispatchArea();
        $hospital = $allocation->getHospital();
        if (!$origin instanceof DispatchArea || !$hospital instanceof Hospital) {
            return CatalogOrientationMap::disabled();
        }

        $originMap = $this->forDispatchArea($origin->getId());
        if (!$originMap->enabled) {
            return CatalogOrientationMap::disabled();
        }

        $latitude = $hospital->getLatitude();
        $longitude = $hospital->getLongitude();
        $hasMarker = null !== $latitude && null !== $longitude;
        $destinationKey = $this->destinationHighlightKey(
            $originMap->highlightKey,
            $hospital->getDispatchArea()?->getId(),
        );

        $recordedTravelMinutes = IsochroneTravelBand::minutesBetween(
            $allocation->getCreatedAt(),
            $allocation->getArrivalAt(),
        );
        $isochrones = null;
        if ($hasMarker && null !== $recordedTravelMinutes) {
            $catalog = $this->isochroneProvider->findForHospital($hospital);
            $isochrones = null !== $catalog
                ? IsochroneTravelBand::collectionForRecordedMinutes($catalog, $recordedTravelMinutes)
                : null;
        }

        return new CatalogOrientationMap(
            enabled: true,
            highlightKey: $originMap->highlightKey,
            markerLatitude: $hasMarker ? $latitude : null,
            markerLongitude: $hasMarker ? $longitude : null,
            markerLabel: $hasMarker ? $hospital->getName() : null,
            destinationHighlightKey: $destinationKey,
            showRoute: $hasMarker,
            districtLabel: null === $originMap->highlightKey ? null : $this->geoIndex->snapshot()->districtLabel($originMap->highlightKey),
            isochronesGeoJson: $isochrones,
            recordedTravelMinutes: $recordedTravelMinutes,
            contextMarkers: $this->contextMarkersForSecondaryTransport($allocation, $hospital),
        );
    }

    /**
     * @return list<array{lat: float, lng: float, label: string}>
     */
    private function contextMarkersForSecondaryTransport(Allocation $allocation, Hospital $destination): array
    {
        if (!$allocation->getSecondaryTransport() instanceof SecondaryTransport) {
            return [];
        }

        $origin = $allocation->getDispatchArea();
        if (!$origin instanceof DispatchArea) {
            return [];
        }

        $markers = [];
        foreach ($this->hospitalLookup->findByDispatchArea($origin) as $candidate) {
            if ($this->isSameHospital($candidate, $destination)) {
                continue;
            }

            $latitude = $candidate->getLatitude();
            $longitude = $candidate->getLongitude();
            $name = $candidate->getName();
            if (!\is_float($latitude) || !\is_float($longitude) || !\is_string($name) || '' === $name) {
                continue;
            }

            $markers[] = [
                'lat' => $latitude,
                'lng' => $longitude,
                'label' => $name,
            ];
        }

        return $markers;
    }

    private function isSameHospital(Hospital $left, Hospital $right): bool
    {
        if ($left === $right) {
            return true;
        }

        $leftId = $left->getId();
        $rightId = $right->getId();

        return null !== $leftId && $leftId === $rightId;
    }

    private function destinationHighlightKey(?string $originKey, ?int $hospitalDispatchAreaId): ?string
    {
        $destinationKey = $this->shapeKey($hospitalDispatchAreaId);

        return $destinationKey === $originKey ? null : $destinationKey;
    }

    private function shapeKey(?int $dispatchAreaId): ?string
    {
        if (null === $dispatchAreaId) {
            return null;
        }

        $key = DispatchAreaGeoSnapshot::keyFor($dispatchAreaId);

        return $this->geoIndex->snapshot()->hasShape($key) ? $key : null;
    }
}
