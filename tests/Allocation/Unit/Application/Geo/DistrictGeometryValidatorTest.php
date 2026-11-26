<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Geo;

use App\Allocation\Application\Geo\DistrictGeometryValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DistrictGeometryValidatorTest extends TestCase
{
    private const array RING = [[8.6, 49.9], [8.7, 49.9], [8.7, 50.0], [8.6, 49.9]];

    public function testAcceptsAPolygonAndAMultiPolygon(): void
    {
        self::assertTrue(DistrictGeometryValidator::isUsable(['type' => 'Polygon', 'coordinates' => [self::RING]]));
        self::assertTrue(DistrictGeometryValidator::isUsable(['type' => 'MultiPolygon', 'coordinates' => [[self::RING]]]));
    }

    public function testAcceptsAClosedRingWhoseCoordinatesLostTheirDecimals(): void
    {
        $decoded = json_decode('{"type":"Polygon","coordinates":[[[8.0,50],[8.1,50.0],[8.1,50.1],[8,50.0]]]}', true, 8, \JSON_THROW_ON_ERROR);

        self::assertTrue(DistrictGeometryValidator::isUsable($decoded));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unusable(): iterable
    {
        yield 'no coordinates' => [['type' => 'Polygon']];
        yield 'empty coordinates' => [['type' => 'Polygon', 'coordinates' => []]];
        yield 'point' => [['type' => 'Point', 'coordinates' => [8.6, 49.9]]];
        yield 'empty polygon' => [['type' => 'MultiPolygon', 'coordinates' => [[]]]];
        yield 'open ring' => [['type' => 'Polygon', 'coordinates' => [[[8.6, 49.9], [8.7, 49.9], [8.7, 50.0], [8.6, 50.0]]]]];
        yield 'too few positions' => [['type' => 'Polygon', 'coordinates' => [[[8.6, 49.9], [8.7, 49.9], [8.6, 49.9]]]]];
        yield 'swapped axes' => [['type' => 'Polygon', 'coordinates' => [[[49.9, 8.6], [49.9, 8.7], [50.0, 8.7], [49.9, 8.6]]]]];
        yield 'projected metres' => [['type' => 'Polygon', 'coordinates' => [[[475000.0, 5530000.0], [476000.0, 5530000.0], [476000.0, 5531000.0], [475000.0, 5530000.0]]]]];
        yield 'text position' => [['type' => 'Polygon', 'coordinates' => [[['a', 'b'], [8.7, 49.9], [8.7, 50.0], ['a', 'b']]]]];
    }

    /**
     * @param array<string, mixed> $geometry
     */
    #[DataProvider('unusable')]
    public function testRejects(array $geometry): void
    {
        self::assertFalse(DistrictGeometryValidator::isUsable($geometry));
    }
}
