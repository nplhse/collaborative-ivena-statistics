<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\Catalog;

use App\Allocation\Application\Contracts\HospitalIsochroneProviderInterface;
use App\Allocation\Application\Contracts\HospitalLookupInterface;
use App\Allocation\Application\DTO\CatalogOrientationMap;
use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\SecondaryTransport;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Resolves Hessen GeoJSON orientation for Explore catalog detail pages.
 */
final readonly class CatalogOrientationMapFactory
{
    private const string HESSEN_STATE_NAME = 'Hessen';

    /** @var array<string, string> */
    private array $nameToGeoKey;

    /** @var array<string, string> */
    private array $districtLabels;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/case_flow/dispatch_area_geo_map.yaml')]
        string $configPath,
        #[Autowire('%kernel.project_dir%/assets/geo/hessen-landkreise.geojson')]
        string $geoJsonPath,
        private HospitalIsochroneProviderInterface $isochroneProvider,
        private HospitalLookupInterface $hospitalLookup,
    ) {
        $this->nameToGeoKey = $this->loadNameToGeoKey($configPath);
        $this->districtLabels = $this->loadDistrictLabels($geoJsonPath);
    }

    public function forDispatchArea(?string $name): CatalogOrientationMap
    {
        if (null === $name || '' === trim($name)) {
            return CatalogOrientationMap::disabled();
        }

        $key = $this->resolveGeoKey($name);
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

        if (0 !== strcasecmp(trim($name), self::HESSEN_STATE_NAME)) {
            return CatalogOrientationMap::disabled();
        }

        return new CatalogOrientationMap(enabled: true, showAllAreas: true);
    }

    public function forHospital(
        ?string $dispatchAreaName,
        ?float $latitude,
        ?float $longitude,
        ?string $markerLabel = null,
    ): CatalogOrientationMap {
        $areaMap = $this->forDispatchArea($dispatchAreaName);
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

        $originMap = $this->forDispatchArea($origin->getName());
        if (!$originMap->enabled) {
            return CatalogOrientationMap::disabled();
        }

        $latitude = $hospital->getLatitude();
        $longitude = $hospital->getLongitude();
        $hasMarker = null !== $latitude && null !== $longitude;
        $destinationKey = $this->destinationHighlightKey(
            $originMap->highlightKey,
            $hospital->getDispatchArea()?->getName(),
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
            districtLabel: $this->districtLabelForKey($originMap->highlightKey),
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

    private function destinationHighlightKey(?string $originKey, ?string $hospitalDispatchAreaName): ?string
    {
        if (null === $hospitalDispatchAreaName || '' === trim($hospitalDispatchAreaName)) {
            return null;
        }

        $destinationKey = $this->resolveGeoKey($hospitalDispatchAreaName);
        if (null === $destinationKey || $destinationKey === $originKey) {
            return null;
        }

        return $destinationKey;
    }

    private function districtLabelForKey(?string $key): ?string
    {
        if (null === $key || !isset($this->districtLabels[$key])) {
            return null;
        }

        $label = $this->districtLabels[$key];

        return '' === $label ? null : $label;
    }

    private function resolveGeoKey(string $originName): ?string
    {
        if (isset($this->nameToGeoKey[$originName])) {
            return $this->nameToGeoKey[$originName];
        }

        $normalized = mb_strtolower(trim($originName));
        foreach ($this->nameToGeoKey as $name => $geoKey) {
            if (mb_strtolower($name) === $normalized) {
                return $geoKey;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function loadNameToGeoKey(string $configPath): array
    {
        if (!is_file($configPath)) {
            return [];
        }

        $parsed = Yaml::parseFile($configPath);
        /** @var array<string, string> $mapping */
        $mapping = \is_array($parsed) ? ($parsed['dispatch_area_geo_map'] ?? []) : [];

        return $mapping;
    }

    /**
     * @return array<string, string>
     */
    private function loadDistrictLabels(string $geoJsonPath): array
    {
        if (!is_file($geoJsonPath)) {
            return [];
        }

        $raw = file_get_contents($geoJsonPath);
        if (false === $raw || '' === $raw) {
            return [];
        }

        try {
            $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!\is_array($parsed) || !isset($parsed['features']) || !\is_array($parsed['features'])) {
            return [];
        }

        $labels = [];
        foreach ($parsed['features'] as $feature) {
            if (!\is_array($feature)) {
                continue;
            }

            $properties = $feature['properties'] ?? null;
            if (!\is_array($properties)) {
                continue;
            }

            $key = $properties['key'] ?? null;
            $names = $properties['krs_names'] ?? null;
            if (!\is_string($key) || '' === $key || !\is_array($names)) {
                continue;
            }

            $clean = [];
            foreach ($names as $name) {
                if (\is_string($name) && '' !== trim($name)) {
                    $clean[] = trim($name);
                }
            }

            if ([] !== $clean) {
                $labels[$key] = implode(', ', $clean);
            }
        }

        return $labels;
    }
}
