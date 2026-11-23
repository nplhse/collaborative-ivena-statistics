<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Geo;

use App\Allocation\Application\Contracts\DispatchAreaGeoIndexInterface;
use App\Allocation\Application\Geo\DistrictGeometryValidator;
use App\Allocation\Application\Geo\DTO\DispatchAreaGeoJson;
use App\Allocation\Application\Geo\DTO\DispatchAreaGeoSnapshot;
use App\Allocation\Application\Geo\DTO\DispatchAreaShapeStatus;
use App\Allocation\Infrastructure\Query\DispatchAreaShapeQuery;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Builds the map shapes from the database, one feature per dispatch area with a complete shape (ADR 041).
 *
 * The cache key contains a fingerprint of the source data, so every committed change of an assignment, a
 * geometry or a catalog row, also from an import or a restore, takes effect on the next read.
 *
 * @psalm-suppress UnusedClass Wired via DispatchAreaGeoIndexInterface autowiring.
 */
final readonly class DispatchAreaGeoIndex implements DispatchAreaGeoIndexInterface
{
    private const int CACHE_SECONDS = 86_400;

    public function __construct(
        private DispatchAreaShapeQuery $query,
        #[Autowire(service: 'cache.geo.dispatch_area_shapes')]
        private CacheInterface $cache,
    ) {
    }

    #[\Override]
    public function snapshot(): DispatchAreaGeoSnapshot
    {
        $areas = $this->cache->get('index.'.$this->query->fingerprint(), function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_SECONDS);

            $areas = [];
            foreach ($this->completeAreas() as $key => $area) {
                $areas[$key] = ['state' => $area['state'], 'districts' => $area['districts']];
            }

            return $areas;
        });

        return new DispatchAreaGeoSnapshot($areas);
    }

    #[\Override]
    public function geoJson(): DispatchAreaGeoJson
    {
        $fingerprint = $this->query->fingerprint();
        $content = $this->cache->get('geojson.'.$fingerprint, function (ItemInterface $item): string {
            $item->expiresAfter(self::CACHE_SECONDS);

            $features = [];
            foreach ($this->completeAreas() as $key => $area) {
                $features[] = [
                    'type' => 'Feature',
                    'properties' => [
                        'key' => (string) $key,
                        'name' => $area['name'],
                        'state' => $area['state'],
                        'neighbour' => !$area['hasHospital'],
                        'districts' => $area['districts'],
                        'attributions' => array_keys($area['attributions']),
                    ],
                    'geometry' => ['type' => 'MultiPolygon', 'coordinates' => $area['polygons']],
                ];
            }

            return json_encode(
                ['type' => 'FeatureCollection', 'features' => $features],
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION,
            );
        });

        return new DispatchAreaGeoJson($content, $fingerprint);
    }

    /**
     * @return array<array-key, array{name: string, state: string, hasHospital: bool, districts: list<string>, attributions: array<string, true>, polygons: list<mixed>}>
     */
    private function completeAreas(): array
    {
        $areas = [];
        foreach ($this->query->rows() as $row) {
            $key = DispatchAreaGeoSnapshot::keyFor($row['id']);
            $areas[$key] ??= ['name' => $row['name'], 'state' => $row['state_name'], 'hasHospital' => $row['has_hospital'], 'count' => 0, 'missing' => [], 'districts' => [], 'attributions' => [], 'polygons' => []];

            if (null === $row['ags']) {
                continue;
            }

            ++$areas[$key]['count'];
            $areas[$key]['districts'][] = trim(sprintf('%s %s', (string) $row['district_kind'], (string) $row['district_name']));
            $polygons = $this->polygons($row['geometry']);
            if (null === $polygons) {
                $areas[$key]['missing'][] = $row['ags'];

                continue;
            }

            array_push($areas[$key]['polygons'], ...$polygons);
            if (null !== $row['attribution'] && '' !== $row['attribution']) {
                $areas[$key]['attributions'][$row['attribution']] = true;
            }
        }

        $complete = [];
        foreach ($areas as $key => $area) {
            if (new DispatchAreaShapeStatus($area['name'], false, $area['count'], $area['missing'])->hasShape()) {
                unset($area['count'], $area['missing']);
                $complete[$key] = $area;
            }
        }

        return $complete;
    }

    /**
     * @return list<mixed>|null polygons of a usable geometry
     */
    private function polygons(?string $geometry): ?array
    {
        if (null === $geometry) {
            return null;
        }

        try {
            $decoded = json_decode($geometry, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($decoded) || !DistrictGeometryValidator::isUsable($decoded)) {
            return null;
        }

        /** @var list<mixed> $coordinates */
        $coordinates = $decoded['coordinates'];

        return 'Polygon' === $decoded['type'] ? [$coordinates] : $coordinates;
    }
}
