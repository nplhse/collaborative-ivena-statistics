<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Faker\Provider;

use Random\Randomizer;

/**
 * District shapes from `assets/geo/hessen-landkreise.geojson` (BKG VG250, public).
 *
 * Draws coordinates inside a district, so synthetic hospitals lie in the area they belong to.
 * The file is read once per process.
 */
final class HesseDistrictPolygons
{
    public const string GEOJSON = 'assets/geo/hessen-landkreise.geojson';

    private const int MAX_ATTEMPTS = 500;

    /** @var array<string, list<list<list<array{0: float, 1: float}>>>>|null geo key → polygons → rings → [lng, lat] */
    private static ?array $polygons = null;

    /**
     * A random point inside the district; randomness comes from Faker, so a seed makes it repeatable.
     *
     * @return array{lat: float, lng: float}
     */
    public static function randomPoint(string $geoKey, Randomizer $random): array
    {
        [$minLng, $minLat, $maxLng, $maxLat] = self::bounds($geoKey);

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt) {
            $lng = self::between($minLng, $maxLng, $random);
            $lat = self::between($minLat, $maxLat, $random);
            if (self::contains($geoKey, $lat, $lng)) {
                return ['lat' => $lat, 'lng' => $lng];
            }
        }

        throw new \RuntimeException(sprintf('No point found inside district "%s".', $geoKey));
    }

    public static function contains(string $geoKey, float $lat, float $lng): bool
    {
        foreach (self::polygonsOf($geoKey) as $rings) {
            $outer = array_shift($rings);
            if (null === $outer || !self::inRing($outer, $lat, $lng)) {
                continue;
            }

            foreach ($rings as $hole) {
                if (self::inRing($hole, $lat, $lng)) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function geoKeys(): array
    {
        return array_keys(self::all());
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float} min lng, min lat, max lng, max lat
     */
    private static function bounds(string $geoKey): array
    {
        $minLng = $minLat = \INF;
        $maxLng = $maxLat = -\INF;
        foreach (self::polygonsOf($geoKey) as $rings) {
            foreach ($rings[0] ?? [] as [$lng, $lat]) {
                $minLng = min($minLng, $lng);
                $maxLng = max($maxLng, $lng);
                $minLat = min($minLat, $lat);
                $maxLat = max($maxLat, $lat);
            }
        }

        return [$minLng, $minLat, $maxLng, $maxLat];
    }

    /**
     * Ray casting; rings are lists of [lng, lat].
     *
     * @param list<array{0: float, 1: float}> $ring
     */
    private static function inRing(array $ring, float $lat, float $lng): bool
    {
        $inside = false;
        $previous = end($ring);
        if (false === $previous) {
            return false;
        }

        foreach ($ring as $point) {
            [$xi, $yi] = $point;
            [$xj, $yj] = $previous;
            if (($yi > $lat) !== ($yj > $lat) && $lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi) {
                $inside = !$inside;
            }
            $previous = $point;
        }

        return $inside;
    }

    private static function between(float $min, float $max, Randomizer $random): float
    {
        return round($min + ($max - $min) * ((float) $random->getInt(0, 1_000_000) / 1_000_000.0), 6);
    }

    /**
     * @return list<list<list<array{0: float, 1: float}>>>
     */
    private static function polygonsOf(string $geoKey): array
    {
        return self::all()[$geoKey] ?? throw new \InvalidArgumentException(sprintf('Unknown district geo key "%s".', $geoKey));
    }

    /**
     * @return array<string, list<list<list<array{0: float, 1: float}>>>>
     */
    private static function all(): array
    {
        if (null !== self::$polygons) {
            return self::$polygons;
        }

        $path = \dirname(__DIR__, 5).'/'.self::GEOJSON;
        /** @var array{features: list<array{properties: array{key: string}, geometry: array{type: string, coordinates: list<mixed>}}>} $data */
        $data = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

        $polygons = [];
        foreach ($data['features'] as $feature) {
            $geometry = $feature['geometry'];
            /** @var list<list<list<array{0: float, 1: float}>>> $shapes */
            $shapes = 'MultiPolygon' === $geometry['type'] ? $geometry['coordinates'] : [$geometry['coordinates']];
            $polygons[$feature['properties']['key']] = $shapes;
        }

        return self::$polygons = $polygons;
    }
}
