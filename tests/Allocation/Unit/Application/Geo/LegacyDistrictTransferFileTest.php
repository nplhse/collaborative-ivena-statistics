<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Geo;

use App\Allocation\Application\Geo\LegacyDistrictTakeover;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Proves the shipped reference data against the old map path. Removed together with that path (#1406, step 7).
 */
final class LegacyDistrictTransferFileTest extends TestCase
{
    public function testReferenceDataMatchesTheOldMapPath(): void
    {
        $root = \dirname(__DIR__, 5);
        $transfer = json_decode((string) file_get_contents($root.'/resources/geo/legacy-districts-1406.geojson'), true, 32, \JSON_THROW_ON_ERROR);
        $names = Yaml::parseFile($root.'/config/case_flow/dispatch_area_geo_map.yaml')['dispatch_area_geo_map'];
        $old = json_decode((string) file_get_contents($root.'/assets/geo/hessen-landkreise.geojson'), true, 32, \JSON_THROW_ON_ERROR);

        $oldByKey = array_column($old['features'], null, null);
        $oldByKey = array_combine(array_map(static fn (array $f): string => $f['properties']['key'], $oldByKey), $oldByKey);
        $geometryByAgs = [];
        foreach ($transfer['features'] as $feature) {
            $geometryByAgs[$feature['properties']['ags']] = $feature['geometry'];
        }

        self::assertCount(29, $transfer['areas']);
        self::assertCount(37, $transfer['features']);
        self::assertSame(array_keys($names), array_column($transfer['areas'], 'name'));

        foreach ($transfer['areas'] as $area) {
            $oldGeometry = $oldByKey[$names[$area['name']]]['geometry'];
            $districts = array_map(static fn (string $ags): array => $geometryByAgs[$ags], $area['ags']);

            self::assertSame(LegacyDistrictTakeover::geometryHash([$oldGeometry]), $area['geometry_sha256'], $area['name']);
            self::assertSame($area['geometry_sha256'], LegacyDistrictTakeover::geometryHash($districts), $area['name']);
        }
    }
}
