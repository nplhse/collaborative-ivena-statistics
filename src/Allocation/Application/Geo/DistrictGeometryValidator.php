<?php

declare(strict_types=1);

namespace App\Allocation\Application\Geo;

/**
 * Accepts a GeoJSON polygon or multipolygon in WGS84 (longitude first) around Germany. The bounds catch swapped axes
 * and projected coordinates.
 */
final class DistrictGeometryValidator
{
    private const float MIN_LONGITUDE = 5.0;
    private const float MAX_LONGITUDE = 16.0;
    private const float MIN_LATITUDE = 47.0;
    private const float MAX_LATITUDE = 56.0;

    /**
     * @param array<array-key, mixed> $geometry
     */
    public static function isUsable(array $geometry): bool
    {
        $coordinates = $geometry['coordinates'] ?? null;
        if (!\is_array($coordinates) || [] === $coordinates) {
            return false;
        }

        $polygons = match ($geometry['type'] ?? null) {
            'Polygon' => [$coordinates],
            'MultiPolygon' => $coordinates,
            default => null,
        };
        if (null === $polygons) {
            return false;
        }

        foreach ($polygons as $polygon) {
            if (!\is_array($polygon) || [] === $polygon) {
                return false;
            }
            foreach ($polygon as $ring) {
                if (!self::isUsableRing($ring)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function isUsableRing(mixed $ring): bool
    {
        if (!\is_array($ring) || !array_is_list($ring) || \count($ring) < 4) {
            return false;
        }

        $positions = [];
        foreach ($ring as $position) {
            if (!\is_array($position) || !\is_numeric($position[0] ?? null) || !\is_numeric($position[1] ?? null)) {
                return false;
            }

            // JSON turns 50.0 into the integer 50, so positions are compared as floats.
            [$longitude, $latitude] = [(float) $position[0], (float) $position[1]];
            if ($longitude < self::MIN_LONGITUDE || $longitude > self::MAX_LONGITUDE
                || $latitude < self::MIN_LATITUDE || $latitude > self::MAX_LATITUDE) {
                return false;
            }
            $positions[] = [$longitude, $latitude];
        }

        return $positions[0] === $positions[\count($positions) - 1];
    }
}
