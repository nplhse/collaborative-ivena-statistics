<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Infrastructure\GeoJson;

/**
 * Builds merged Hessen dispatch-area GeoJSON from raw Kreis boundaries.
 */
final class HessenDispatchAreaGeoJsonBuilder
{
    /**
     * @param array<string, mixed> $sourcesConfig parsed dispatch_area_geo_sources.yaml
     *
     * @return array{type: string, features: list<array<string, mixed>>}
     */
    public function build(array $sourcesConfig, string $rawGeoJsonPath): array
    {
        if (!is_file($rawGeoJsonPath)) {
            throw new \InvalidArgumentException(sprintf('Raw GeoJSON not found: %s', $rawGeoJsonPath));
        }

        $raw = json_decode((string) file_get_contents($rawGeoJsonPath), true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, array<string, mixed>> $byKrsName */
        $byKrsName = [];

        foreach ($raw['features'] ?? [] as $feature) {
            $krsName = $this->normalizeKrsName($feature['properties']['krs_name'] ?? null);
            if (null === $krsName) {
                continue;
            }
            $byKrsName[$krsName] = $feature;
        }

        /** @var array<string, array{name: string, krs_names: list<string>}> $dispatchAreas */
        $dispatchAreas = $sourcesConfig['dispatch_areas'] ?? [];
        $features = [];

        foreach ($dispatchAreas as $key => $definition) {
            $geometries = [];
            $mergedKrsNames = [];

            foreach ($definition['krs_names'] as $krsName) {
                if (!isset($byKrsName[$krsName])) {
                    throw new \RuntimeException(sprintf('Kreis geometry missing for "%s" (dispatch key "%s")', $krsName, $key));
                }
                $geometries[] = $byKrsName[$krsName]['geometry'];
                $mergedKrsNames[] = $krsName;
            }

            $features[] = [
                'type' => 'Feature',
                'properties' => [
                    'key' => $key,
                    'name' => $definition['name'],
                    'krs_names' => $mergedKrsNames,
                ],
                'geometry' => $this->mergeGeometries($geometries),
            ];
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
        ];
    }

    private function normalizeKrsName(mixed $value): ?string
    {
        if (\is_array($value)) {
            $value = $value[0] ?? null;
        }

        if (!\is_string($value) || '' === trim($value)) {
            return null;
        }

        return trim($value);
    }

    /**
     * @param list<array<string, mixed>> $geometries
     *
     * @return array<string, mixed>
     */
    private function mergeGeometries(array $geometries): array
    {
        /** @var list<list<list<list<float>>>> $polygons */
        $polygons = [];

        foreach ($geometries as $geometry) {
            $type = $geometry['type'] ?? null;
            $coordinates = $geometry['coordinates'] ?? null;

            if (!\is_string($type) || !\is_array($coordinates)) {
                continue;
            }

            if ('Polygon' === $type) {
                $polygons[] = $coordinates;

                continue;
            }

            if ('MultiPolygon' === $type) {
                foreach ($coordinates as $polygon) {
                    if (\is_array($polygon)) {
                        $polygons[] = $polygon;
                    }
                }
            }
        }

        if ([] === $polygons) {
            throw new \RuntimeException('No polygon coordinates to merge.');
        }

        if (1 === \count($polygons)) {
            return [
                'type' => 'Polygon',
                'coordinates' => $polygons[0],
            ];
        }

        return [
            'type' => 'MultiPolygon',
            'coordinates' => $polygons,
        ];
    }
}
