<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital;

/**
 * Origin coordinates stored on destination-isochrone FeatureCollections.
 */
final class IsochroneOrigin
{
    public const float TOLERANCE = 1.0e-5;

    /**
     * @param array{type: string, features: list<array<string, mixed>>, properties?: array<string, mixed>} $geojson
     *
     * @return array{type: string, features: list<array<string, mixed>>, properties: array{origin: array{lat: float, lng: float}}}
     */
    public static function withCoordinates(array $geojson, float $latitude, float $longitude): array
    {
        $geojson['properties'] = [
            'origin' => [
                'lat' => $latitude,
                'lng' => $longitude,
            ],
        ];

        return $geojson;
    }

    /**
     * @param array{type: string, features: list<array<string, mixed>>, properties?: array<string, mixed>}|null $geojson
     */
    public static function matches(?array $geojson, float $latitude, float $longitude): bool
    {
        if (null === $geojson) {
            return false;
        }

        $origin = $geojson['properties']['origin'] ?? null;
        if (!\is_array($origin)) {
            return false;
        }

        $storedLatitude = $origin['lat'] ?? null;
        $storedLongitude = $origin['lng'] ?? null;
        if (!\is_int($storedLatitude) && !\is_float($storedLatitude)) {
            return false;
        }
        if (!\is_int($storedLongitude) && !\is_float($storedLongitude)) {
            return false;
        }

        return abs((float) $storedLatitude - $latitude) < self::TOLERANCE
            && abs((float) $storedLongitude - $longitude) < self::TOLERANCE;
    }
}
