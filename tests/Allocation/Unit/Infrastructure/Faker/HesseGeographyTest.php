<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Infrastructure\Faker;

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

    public function testDistrictsAreExactlyTheDispatchAreasOfTheGeoMap(): void
    {
        /** @var array{dispatch_area_geo_map: array<string, string>} $config */
        $config = Yaml::parseFile(self::PROJECT.'/config/case_flow/dispatch_area_geo_map.yaml');

        $fromProvider = array_map(static fn (array $district): string => $district['geo_key'], HesseDistricts::DISTRICTS);

        self::assertSame($config['dispatch_area_geo_map'], $fromProvider);
    }

    public function testEveryDistrictHasAShapeOnTheMap(): void
    {
        /** @var array{features: list<array{properties: array{key: string, name: string}}>} $geojson */
        $geojson = json_decode((string) file_get_contents(self::PROJECT.'/'.HesseDistrictPolygons::GEOJSON), true, 512, \JSON_THROW_ON_ERROR);
        $names = [];
        foreach ($geojson['features'] as $feature) {
            $names[$feature['properties']['key']] = $feature['properties']['name'];
        }

        foreach (HesseDistricts::DISTRICTS as $name => $district) {
            self::assertSame($name, $names[$district['geo_key']] ?? null, sprintf('%s has no feature on the map', $name));
        }
        self::assertCount(\count($names), HesseDistricts::DISTRICTS);
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
        $geoKey = HesseDistricts::DISTRICTS[$dispatchArea]['geo_key'];

        for ($i = 0; $i < 10; ++$i) {
            $point = Area::coordinatesIn($dispatchArea);
            self::assertTrue(HesseDistrictPolygons::contains($geoKey, $point['lat'], $point['lng']), sprintf('%s: %F, %F', $dispatchArea, $point['lat'], $point['lng']));
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
        foreach (HesseDistrictPolygons::geoKeys() as $geoKey) {
            self::assertFalse(HesseDistrictPolygons::contains($geoKey, 48.137, 11.575), 'Munich is not in '.$geoKey);
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
            self::assertTrue(HesseDistrictPolygons::contains(HesseDistricts::DISTRICTS[$area]['geo_key'], $hospital['coordinates']['lat'], $hospital['coordinates']['lng']));
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
            $profiles[] = $faker->hospitalProfile($area);
        }

        return $profiles;
    }

    private function faker(int $seed): Generator
    {
        $faker = Factory::create('de_DE');
        $faker->addProvider(new Hospital($faker));
        $faker->seed($seed);

        return $faker;
    }
}
