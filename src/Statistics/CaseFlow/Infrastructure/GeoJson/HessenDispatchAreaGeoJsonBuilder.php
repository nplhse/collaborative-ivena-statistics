<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Infrastructure\GeoJson;

/**
 * Builds merged dispatch-area GeoJSON from raw Kreis boundaries: the Hessian dispatch areas plus
 * the neighbouring ones outside Hesse that are configured in dispatch_area_geo_sources.yaml.
 */
final class HessenDispatchAreaGeoJsonBuilder
{
    /**
     * @param array<string, mixed> $sourcesConfig   parsed dispatch_area_geo_sources.yaml
     * @param list<string>         $rawGeoJsonPaths raw Kreis GeoJSON files (BKG VG250 via Opendatasoft)
     *
     * @return array{type: string, features: list<array<string, mixed>>}
     */
    public function build(array $sourcesConfig, array $rawGeoJsonPaths): array
    {
        /** @var array<string, array<string, mixed>> $byKrsName */
        $byKrsName = [];

        foreach ($rawGeoJsonPaths as $rawGeoJsonPath) {
            if (!is_file($rawGeoJsonPath)) {
                throw new \InvalidArgumentException(sprintf('Raw GeoJSON not found: %s', $rawGeoJsonPath));
            }

            $raw = json_decode((string) file_get_contents($rawGeoJsonPath), true, 512, JSON_THROW_ON_ERROR);

            foreach ($raw['features'] ?? [] as $feature) {
                $krsName = $this->firstString($feature['properties']['krs_name'] ?? null);
                if (null === $krsName) {
                    continue;
                }
                $byKrsName[$krsName] = $feature;
            }
        }

        $coreState = $sourcesConfig['core_state'] ?? null;
        if (!\is_string($coreState) || '' === trim($coreState)) {
            throw new \RuntimeException('dispatch_area_geo_sources.yaml needs a core_state.');
        }

        /** @var array<string, array{name: string, state?: string, krs_names: list<string>, krs_codes?: list<string>}> $dispatchAreas */
        $dispatchAreas = $sourcesConfig['dispatch_areas'] ?? [];
        $features = [];

        foreach ($dispatchAreas as $key => $definition) {
            $state = $definition['state'] ?? $coreState;
            $krsCodes = $definition['krs_codes'] ?? null;
            if (null !== $krsCodes && \count($krsCodes) !== \count($definition['krs_names'])) {
                throw new \RuntimeException(sprintf('krs_codes and krs_names differ in length (dispatch key "%s")', $key));
            }

            $geometries = [];
            $mergedKrsNames = [];

            foreach ($definition['krs_names'] as $index => $krsName) {
                if (!isset($byKrsName[$krsName])) {
                    throw new \RuntimeException(sprintf('Kreis geometry missing for "%s" (dispatch key "%s")', $krsName, $key));
                }

                $rawCode = $this->firstString($byKrsName[$krsName]['properties']['krs_code'] ?? null);
                if (null !== $krsCodes && $rawCode !== $krsCodes[$index]) {
                    throw new \RuntimeException(sprintf('Kreis "%s" has code %s, configured %s (dispatch key "%s")', $krsName, $rawCode ?? 'none', $krsCodes[$index], $key));
                }

                $geometries[] = $byKrsName[$krsName]['geometry'];
                $mergedKrsNames[] = $krsName;
            }

            $features[] = [
                'type' => 'Feature',
                'properties' => [
                    'key' => $key,
                    'name' => $definition['name'],
                    'state' => $state,
                    'neighbour' => $state !== $coreState,
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

    private function firstString(mixed $value): ?string
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
