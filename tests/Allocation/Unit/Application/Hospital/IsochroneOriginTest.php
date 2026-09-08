<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Hospital;

use App\Allocation\Application\Hospital\IsochroneOrigin;
use PHPUnit\Framework\TestCase;

final class IsochroneOriginTest extends TestCase
{
    public function testWithCoordinatesAddsOriginProperties(): void
    {
        $geojson = IsochroneOrigin::withCoordinates($this->collection(), 50.1109, 8.6821);

        self::assertSame(['lat' => 50.1109, 'lng' => 8.6821], $geojson['properties']['origin']);
        self::assertCount(1, $geojson['features']);
    }

    public function testMatchesStoredOriginWithinTolerance(): void
    {
        $geojson = IsochroneOrigin::withCoordinates($this->collection(), 50.1109, 8.6821);

        self::assertTrue(IsochroneOrigin::matches($geojson, 50.1109, 8.6821));
        self::assertTrue(IsochroneOrigin::matches($geojson, 50.110900001, 8.682100001));
        self::assertFalse(IsochroneOrigin::matches($geojson, 51.3127, 9.4797));
        self::assertFalse(IsochroneOrigin::matches($this->collection(), 50.1109, 8.6821));
        self::assertFalse(IsochroneOrigin::matches(null, 50.1109, 8.6821));
        self::assertFalse(IsochroneOrigin::matches([
            'type' => 'FeatureCollection',
            'features' => [],
            'properties' => ['origin' => ['lat' => '50.1109', 'lng' => 8.6821]],
        ], 50.1109, 8.6821));
        self::assertFalse(IsochroneOrigin::matches([
            'type' => 'FeatureCollection',
            'features' => [],
            'properties' => ['origin' => ['lat' => 50.1109, 'lng' => '8.6821']],
        ], 50.1109, 8.6821));
    }

    /**
     * @return array{type: string, features: list<array<string, mixed>>}
     */
    private function collection(): array
    {
        return [
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'properties' => ['value' => 600],
                    'geometry' => ['type' => 'Polygon', 'coordinates' => []],
                ],
            ],
        ];
    }
}
