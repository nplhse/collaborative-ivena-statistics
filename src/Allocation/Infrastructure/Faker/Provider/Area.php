<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Faker\Provider;

use Faker\Provider\Base;
use Random\Randomizer;

/**
 * Hesse and its dispatch areas for synthetic reference data (ADR 020).
 *
 * Every area has a shape on the maps; towns, postal codes and coordinates fit their area.
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class Area extends Base
{
    public static function federalState(): string
    {
        return HesseDistricts::STATE;
    }

    public static function dispatchArea(): string
    {
        return self::randomElement(HesseDistricts::names());
    }

    /**
     * A town of the area with one of its postal codes.
     *
     * @return array{city: string, postal_code: string, location: 'Urban'|'Mixed'|'Rural'}
     */
    public static function townIn(string $dispatchArea): array
    {
        return self::townFor($dispatchArea, RandomDraw::fromFaker());
    }

    /**
     * @return array{city: string, postal_code: string, location: 'Urban'|'Mixed'|'Rural'}
     */
    public static function townFor(string $dispatchArea, Randomizer $random): array
    {
        return RandomDraw::element(self::district($dispatchArea)['towns'], $random);
    }

    /**
     * Coordinates inside the area's district shape.
     *
     * @return array{lat: float, lng: float}
     */
    public static function coordinatesIn(string $dispatchArea): array
    {
        return self::coordinatesFor($dispatchArea, RandomDraw::fromFaker());
    }

    /**
     * @return array{lat: float, lng: float}
     */
    public static function coordinatesFor(string $dispatchArea, Randomizer $random): array
    {
        return HesseDistrictPolygons::randomPoint(self::district($dispatchArea)['geo_key'], $random);
    }

    /**
     * @return array{geo_key: string, towns: list<array{city: string, postal_code: string, location: 'Urban'|'Mixed'|'Rural'}>}
     */
    private static function district(string $dispatchArea): array
    {
        return HesseDistricts::DISTRICTS[$dispatchArea] ?? throw new \InvalidArgumentException(sprintf('"%s" is not a dispatch area of Hesse.', $dispatchArea));
    }
}
