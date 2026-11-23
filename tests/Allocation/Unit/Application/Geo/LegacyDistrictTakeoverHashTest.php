<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Geo;

use App\Allocation\Application\Geo\LegacyDistrictTakeover;
use PHPUnit\Framework\TestCase;

final class LegacyDistrictTakeoverHashTest extends TestCase
{
    private const array WEST = [[[8.0, 50.0], [8.1, 50.0], [8.1, 50.1], [8.0, 50.0]]];
    private const array EAST = [[[9.0, 50.0], [9.1, 50.0], [9.1, 50.1], [9.0, 50.0]]];

    public function testPolygonOrderDoesNotMatter(): void
    {
        self::assertSame(
            LegacyDistrictTakeover::geometryHash([['type' => 'MultiPolygon', 'coordinates' => [self::WEST, self::EAST]]]),
            LegacyDistrictTakeover::geometryHash([['type' => 'MultiPolygon', 'coordinates' => [self::EAST, self::WEST]]]),
        );
    }

    public function testTwoDistrictsEqualTheirMergedArea(): void
    {
        self::assertSame(
            LegacyDistrictTakeover::geometryHash([['type' => 'MultiPolygon', 'coordinates' => [self::WEST, self::EAST]]]),
            LegacyDistrictTakeover::geometryHash([
                ['type' => 'Polygon', 'coordinates' => self::EAST],
                ['type' => 'Polygon', 'coordinates' => self::WEST],
            ]),
        );
    }

    public function testAMovedPointChangesTheHash(): void
    {
        $moved = self::WEST;
        $moved[0][1][0] = 8.2;

        self::assertNotSame(
            LegacyDistrictTakeover::geometryHash([['type' => 'Polygon', 'coordinates' => self::WEST]]),
            LegacyDistrictTakeover::geometryHash([['type' => 'Polygon', 'coordinates' => $moved]]),
        );
    }
}
