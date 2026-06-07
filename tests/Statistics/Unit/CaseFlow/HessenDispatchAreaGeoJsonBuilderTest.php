<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\CaseFlow;

use App\Statistics\CaseFlow\Infrastructure\GeoJson\HessenDispatchAreaGeoJsonBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class HessenDispatchAreaGeoJsonBuilderTest extends TestCase
{
    public function testBuildMergesKasselCityAndDistrict(): void
    {
        $projectDir = \dirname(__DIR__, 4);
        $sources = Yaml::parseFile($projectDir.'/config/case_flow/dispatch_area_geo_sources.yaml');
        $rawPath = $projectDir.'/assets/geo/.source/hessen-kreise-raw.geojson';

        if (!is_file($rawPath)) {
            self::markTestSkipped('Raw Hessen GeoJSON source file is not available.');
        }

        $builder = new HessenDispatchAreaGeoJsonBuilder();
        $collection = $builder->build($sources, $rawPath);

        $kassel = null;
        foreach ($collection['features'] as $feature) {
            if ('kassel' === $feature['properties']['key']) {
                $kassel = $feature;
                break;
            }
        }

        self::assertNotNull($kassel);
        self::assertSame('Kassel', $kassel['properties']['name']);
        self::assertCount(2, $kassel['properties']['krs_names']);
        self::assertSame('MultiPolygon', $kassel['geometry']['type']);
    }

    public function testBuildMergesOffenbachCityAndDistrict(): void
    {
        $projectDir = \dirname(__DIR__, 4);
        $sources = Yaml::parseFile($projectDir.'/config/case_flow/dispatch_area_geo_sources.yaml');
        $rawPath = $projectDir.'/assets/geo/.source/hessen-kreise-raw.geojson';

        if (!is_file($rawPath)) {
            self::markTestSkipped('Raw Hessen GeoJSON source file is not available.');
        }

        $builder = new HessenDispatchAreaGeoJsonBuilder();
        $collection = $builder->build($sources, $rawPath);

        $offenbach = null;
        foreach ($collection['features'] as $feature) {
            if ('offenbach' === $feature['properties']['key']) {
                $offenbach = $feature;
                break;
            }
        }

        self::assertNotNull($offenbach);
        self::assertCount(2, $offenbach['properties']['krs_names']);
        self::assertSame('MultiPolygon', $offenbach['geometry']['type']);
    }
}
