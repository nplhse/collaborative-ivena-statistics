<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Faker\Provider;

use Faker\Provider\Base;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Coarse district shapes from `resources/geo/demo-districts.geojson` (BKG VG250, simplified).
 *
 * Draws coordinates inside a dispatch area, so synthetic hospitals lie in the area they belong to.
 * An area may cover several districts. The file is read once per process; the maps read the database (ADR 041).
 */
final class HesseDistrictPolygons
{
    public const string GEOJSON = 'resources/geo/demo-districts.geojson';

    private const int MAX_ATTEMPTS = 500;

    /** @var array<string, list<list<list<array{0: float, 1: float}>>>>|null dispatch area → polygons → rings → [lng, lat] */
    private static ?array $polygons = null;

    /**
     * A random point inside the dispatch area. It takes exactly one value from Faker, so a seed makes it repeatable
     * and the shapes cannot change what Faker draws for later hospitals.
     *
     * @return array{lat: float, lng: float}
     */
    public static function randomPoint(string $dispatchArea): array
    {
        [$minLng, $minLat, $maxLng, $maxLat] = self::bounds($dispatchArea);

        $random = new Randomizer(new Mt19937(Base::numberBetween(0, 2_147_483_647)));
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt) {
            $lng = self::between($random, $minLng, $maxLng);
            $lat = self::between($random, $minLat, $maxLat);
            if (self::contains($dispatchArea, $lat, $lng)) {
                return ['lat' => $lat, 'lng' => $lng];
            }
        }

        throw new \RuntimeException(sprintf('No point found inside dispatch area "%s".', $dispatchArea));
    }

    public static function contains(string $dispatchArea, float $lat, float $lng): bool
    {
        foreach (self::polygonsOf($dispatchArea) as $rings) {
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
    public static function areas(): array
    {
        return array_keys(self::all());
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float} min lng, min lat, max lng, max lat
     */
    private static function bounds(string $dispatchArea): array
    {
        $minLng = $minLat = \INF;
        $maxLng = $maxLat = -\INF;
        foreach (self::polygonsOf($dispatchArea) as $rings) {
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

    private static function between(Randomizer $random, float $min, float $max): float
    {
        return round($min + ($max - $min) * ((float) $random->getInt(0, 1_000_000) / 1_000_000.0), 6);
    }

    /**
     * @return list<list<list<array{0: float, 1: float}>>>
     */
    private static function polygonsOf(string $dispatchArea): array
    {
        return self::all()[$dispatchArea] ?? throw new \InvalidArgumentException(sprintf('Unknown dispatch area "%s".', $dispatchArea));
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
        /** @var array{features: list<array{properties: array{ags: string}, geometry: array{coordinates: list<list<array{0: float, 1: float}>>}}>} $data */
        $data = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

        $byAgs = [];
        foreach ($data['features'] as $feature) {
            $byAgs[$feature['properties']['ags']] = $feature['geometry']['coordinates'];
        }

        $polygons = [];
        foreach (HesseDistricts::OFFICIAL_DISTRICTS as $area => $districts) {
            foreach ($districts as $district) {
                $polygons[$area][] = $byAgs[$district['ags']] ?? throw new \RuntimeException(sprintf('No demo shape for district %s.', $district['ags']));
            }
        }

        return self::$polygons = $polygons;
    }
}
