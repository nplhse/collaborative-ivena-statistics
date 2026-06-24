<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Maps dispatch area names to GeoJSON feature keys for Hessen choropleth.
 */
final class CaseFlowGeoKeyResolver
{
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

        $parsed = \Symfony\Component\Yaml\Yaml::parseFile($configPath);
        /** @var array<string, string> $mapping */
        $mapping = \is_array($parsed) ? ($parsed['dispatch_area_geo_map'] ?? []) : [];
        $this->nameToGeoKey = $mapping;
    }

    public function resolve(int $dispatchAreaId, string $originName): string
    {
        unset($dispatchAreaId);

        if (isset($this->nameToGeoKey[$originName])) {
            return $this->nameToGeoKey[$originName];
        }

        $normalized = mb_strtolower(trim($originName));

        foreach ($this->nameToGeoKey as $name => $geoKey) {
            if (mb_strtolower($name) === $normalized) {
                return $geoKey;
            }
        }

        return $this->slugify($originName);
    }

    private function slugify(string $value): string
    {
        $slug = mb_strtolower($value);
        $slug = preg_replace('/[^a-z0-9]+/u', '-', $slug) ?? $slug;

        return trim($slug, '-');
    }
}
