<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\CaseFlow;

use App\Statistics\CaseFlow\Infrastructure\GeoJson\HessenDispatchAreaGeoJsonBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class HessenDispatchAreaGeoJsonBuilderTest extends TestCase
{
    private const string PROJECT = __DIR__.'/../../../..';

    public function testBuildMergesKasselCityAndDistrict(): void
    {
        $kassel = $this->feature($this->buildFromProjectSources(), 'kassel');

        self::assertSame('Kassel', $kassel['properties']['name']);
        self::assertSame('Hessen', $kassel['properties']['state']);
        self::assertFalse($kassel['properties']['neighbour']);
        self::assertCount(2, $kassel['properties']['krs_names']);
        self::assertSame('MultiPolygon', $kassel['geometry']['type']);
    }

    public function testBuildMergesOffenbachCityAndDistrict(): void
    {
        $offenbach = $this->feature($this->buildFromProjectSources(), 'offenbach');

        self::assertCount(2, $offenbach['properties']['krs_names']);
        self::assertSame('MultiPolygon', $offenbach['geometry']['type']);
    }

    public function testNeighbouringDispatchAreasAreMarkedWithTheirState(): void
    {
        $collection = $this->buildFromProjectSources();

        $neighbours = [];
        foreach ($collection['features'] as $feature) {
            if ($feature['properties']['neighbour']) {
                $neighbours[$feature['properties']['key']] = $feature['properties']['state'];
            }
        }

        self::assertSame([
            'goettingen' => 'Niedersachsen',
            'northeim' => 'Niedersachsen',
            'eichsfeld' => 'Thüringen',
            'schweinfurt' => 'Bayern',
            'bayerischer-untermain' => 'Bayern',
        ], $neighbours);
        self::assertCount(24 + 5, $collection['features']);
    }

    public function testSchweinfurtMergesFiveDistricts(): void
    {
        $schweinfurt = $this->feature($this->buildFromProjectSources(), 'schweinfurt');

        self::assertSame([
            'Kreisfreie Stadt Schweinfurt',
            'Landkreis Schweinfurt',
            'Landkreis Haßberge',
            'Landkreis Bad Kissingen',
            'Landkreis Rhön-Grabfeld',
        ], $schweinfurt['properties']['krs_names']);
        self::assertSame('MultiPolygon', $schweinfurt['geometry']['type']);
    }

    public function testDistrictCodeMustMatchTheRawData(): void
    {
        $sources = [
            'core_state' => 'Hessen',
            'dispatch_areas' => [
                'goettingen' => [
                    'name' => 'Göttingen',
                    'state' => 'Niedersachsen',
                    'krs_names' => ['Landkreis Göttingen'],
                    'krs_codes' => ['03155'],
                ],
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Kreis "Landkreis Göttingen" has code 03159, configured 03155');

        new HessenDispatchAreaGeoJsonBuilder()->build($sources, $this->rawPaths());
    }

    public function testCoreStateIsRequired(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('core_state');

        new HessenDispatchAreaGeoJsonBuilder()->build(['dispatch_areas' => []], $this->rawPaths());
    }

    /**
     * @return array{type: string, features: list<array<string, mixed>>}
     */
    private function buildFromProjectSources(): array
    {
        /** @var array<string, mixed> $sources */
        $sources = Yaml::parseFile(self::PROJECT.'/config/case_flow/dispatch_area_geo_sources.yaml');

        return new HessenDispatchAreaGeoJsonBuilder()->build($sources, $this->rawPaths());
    }

    /**
     * @return list<string>
     */
    private function rawPaths(): array
    {
        return [
            self::PROJECT.'/assets/geo/.source/hessen-kreise-raw.geojson',
            self::PROJECT.'/assets/geo/.source/neighbour-kreise-raw.geojson',
        ];
    }

    /**
     * @param array{type: string, features: list<array<string, mixed>>} $collection
     *
     * @return array<string, mixed>
     */
    private function feature(array $collection, string $key): array
    {
        foreach ($collection['features'] as $feature) {
            if ($key === $feature['properties']['key']) {
                return $feature;
            }
        }

        self::fail(sprintf('No feature "%s"', $key));
    }
}
