<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\Catalog;

use App\Allocation\Application\DTO\CatalogOrientationMap;
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

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/case_flow/dispatch_area_geo_map.yaml')]
        string $configPath,
    ) {
        if (!is_file($configPath)) {
            $this->nameToGeoKey = [];

            return;
        }

        $parsed = Yaml::parseFile($configPath);
        /** @var array<string, string> $mapping */
        $mapping = \is_array($parsed) ? ($parsed['dispatch_area_geo_map'] ?? []) : [];
        $this->nameToGeoKey = $mapping;
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
}
