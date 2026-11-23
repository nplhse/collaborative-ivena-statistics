<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Infrastructure\Faker;

use App\Allocation\Application\Geo\DistrictGeometryValidator;
use App\Allocation\Infrastructure\Faker\Provider\Area;
use App\Allocation\Infrastructure\Faker\Provider\HesseDistrictPolygons;
use App\Allocation\Infrastructure\Faker\Provider\HesseDistricts;
use App\Allocation\Infrastructure\Faker\Provider\Hospital;
use App\Allocation\Infrastructure\Faker\Provider\HospitalFakerMethods;
use Faker\Factory;
use Faker\Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class HesseGeographyTest extends TestCase
{
    private const string PROJECT = __DIR__.'/../../../../..';

    public function testDistrictsAreExactlyTheHessianDispatchAreasOfTheGeoMap(): void
    {
        /** @var array{dispatch_area_geo_map: array<string, string>} $config */
        $config = Yaml::parseFile(self::PROJECT.'/config/case_flow/dispatch_area_geo_map.yaml');
        /** @var array{dispatch_areas: array<string, array{state?: string}>} $sources */
        $sources = Yaml::parseFile(self::PROJECT.'/config/case_flow/dispatch_area_geo_sources.yaml');

        // Neighbouring dispatch areas outside Hesse have a map shape but no synthetic hospitals.
        $hessian = array_filter(
            $config['dispatch_area_geo_map'],
            static fn (string $geoKey): bool => !isset($sources['dispatch_areas'][$geoKey]['state']),
        );
        $fromProvider = array_map(static fn (array $district): string => $district['geo_key'], HesseDistricts::DISTRICTS);

        self::assertSame($hessian, $fromProvider);
    }

    public function testEveryDistrictHasAShapeOnTheMap(): void
    {
        /** @var array{features: list<array{properties: array{ags: string, name: string, kind: string}, geometry: array<array-key, mixed>}>} $geojson */
        $geojson = json_decode((string) file_get_contents(self::PROJECT.'/'.HesseDistrictPolygons::GEOJSON), true, 512, \JSON_THROW_ON_ERROR);
        $shapes = [];
        foreach ($geojson['features'] as $feature) {
            self::assertTrue(DistrictGeometryValidator::isUsable($feature['geometry']), $feature['properties']['ags']);
            $shapes[$feature['properties']['ags']] = $feature['properties'];
        }

        self::assertSame(array_keys(HesseDistricts::DISTRICTS), array_keys(HesseDistricts::OFFICIAL_DISTRICTS));
        $expected = [];
        foreach (HesseDistricts::OFFICIAL_DISTRICTS as $districts) {
            foreach ($districts as $district) {
                $expected[$district['ags']] = $district;
                self::assertSame($district['name'], $shapes[$district['ags']]['name'] ?? null);
                self::assertSame($district['kind'], $shapes[$district['ags']]['kind'] ?? null);
            }
        }
        self::assertCount(26, $expected);
        self::assertCount(26, $shapes);
    }

    public function testNeighbouringDemoShapesShareTheirBorder(): void
    {
        /** @var array{features: list<array{properties: array{ags: string}, geometry: array{coordinates: list<list<array{0: float, 1: float}>>}}>} $geojson */
        $geojson = json_decode((string) file_get_contents(self::PROJECT.'/'.HesseDistrictPolygons::GEOJSON), true, 512, \JSON_THROW_ON_ERROR);
        $points = [];
        foreach ($geojson['features'] as $feature) {
            $points[$feature['properties']['ags']] = array_map(static fn (array $point): string => $point[0].'|'.$point[1], $feature['geometry']['coordinates'][0]);
        }

        // Darmstadt / Darmstadt-Dieburg and Frankfurt / Offenbach am Main.
        self::assertGreaterThanOrEqual(2, \count(array_intersect($points['06411'], $points['06432'])));
        self::assertGreaterThanOrEqual(2, \count(array_intersect($points['06412'], $points['06413'])));
    }

    public function testTownsHaveAPostalCodeAndALocation(): void
    {
        foreach (HesseDistricts::DISTRICTS as $name => $district) {
            foreach ($district['towns'] as $town) {
                self::assertMatchesRegularExpression('/^(3[4-7]|6[0-9])\d{3}$/', $town['postal_code'], sprintf('%s: %s', $name, $town['city']));
            }
        }
    }

    #[DataProvider('districts')]
    public function testCoordinatesLieInsideTheirDistrict(string $dispatchArea): void
    {
        $this->faker(7);

        for ($i = 0; $i < 10; ++$i) {
            $point = Area::coordinatesIn($dispatchArea);
            self::assertTrue(HesseDistrictPolygons::contains($dispatchArea, $point['lat'], $point['lng']), sprintf('%s: %F, %F', $dispatchArea, $point['lat'], $point['lng']));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function districts(): iterable
    {
        foreach (HesseDistricts::names() as $name) {
            yield $name => [$name];
        }
    }

    public function testAPointOutsideHesseIsInNoDistrict(): void
    {
        foreach (HesseDistrictPolygons::areas() as $area) {
            self::assertFalse(HesseDistrictPolygons::contains($area, 48.137, 11.575), 'Munich is not in '.$area);
        }
    }

    public function testHospitalProfilesAreConsistent(): void
    {
        /** @var Generator&HospitalFakerMethods $faker */
        $faker = $this->faker(11);

        for ($i = 0; $i < 60; ++$i) {
            $area = Area::dispatchArea();
            $hospital = $faker->hospitalProfile($area);

            $postalCodes = array_column(HesseDistricts::DISTRICTS[$area]['towns'], 'postal_code');
            self::assertContains($hospital['address']['postal_code'], $postalCodes);
            self::assertSame('Hessen', $hospital['address']['state']);
            self::assertTrue(HesseDistrictPolygons::contains($area, $hospital['coordinates']['lat'], $hospital['coordinates']['lng']));
            [$min, $max] = match ($hospital['size']) {
                'Small' => [80, 299],
                'Medium' => [300, 599],
                'Large' => [600, 1300],
            };
            self::assertGreaterThanOrEqual($min, $hospital['beds']);
            self::assertLessThanOrEqual($max, $hospital['beds']);
            if ('Small' === $hospital['size']) {
                self::assertNotSame('Full', $hospital['tier']);
            }
            self::assertNotSame('', $hospital['name']);
        }
    }

    public function testTheSameSeedGivesTheSameHospitals(): void
    {
        $first = $this->profiles(2026);
        $second = $this->profiles(2026);

        self::assertSame($first, $second);
        self::assertNotSame($first, $this->profiles(2027));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function profiles(int $seed): array
    {
        /** @var Generator&HospitalFakerMethods $faker */
        $faker = $this->faker($seed);
        $profiles = [];
        foreach (['Kassel', 'Frankfurt', 'Vogelsberg', 'Offenbach'] as $area) {
            // The cycle collector may run at any point; force it here so a generator discarded by an
            // earlier call reseeds the global state in the middle of this one if it was not freed (#891).
            gc_collect_cycles();
            $profiles[] = $faker->hospitalProfile($area);
        }

        return $profiles;
    }

    private function faker(int $seed): Generator
    {
        $faker = Factory::create('de_DE');
        $faker->addProvider(new Hospital($faker));
        // Free discarded generators first: Generator::__destruct() reseeds the global state at random (#891).
        gc_collect_cycles();
        $faker->seed($seed);

        return $faker;
    }
}
