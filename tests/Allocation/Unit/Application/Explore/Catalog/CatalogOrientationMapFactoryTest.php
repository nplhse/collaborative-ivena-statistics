<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Explore\Catalog;

use App\Allocation\Application\Explore\Catalog\CatalogOrientationMapFactory;
use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use PHPUnit\Framework\TestCase;

final class CatalogOrientationMapFactoryTest extends TestCase
{
    private CatalogOrientationMapFactory $factory;

    protected function setUp(): void
    {
        $projectDir = \dirname(__DIR__, 6);
        $configPath = $projectDir.'/config/case_flow/dispatch_area_geo_map.yaml';
        $geoJsonPath = $projectDir.'/assets/geo/hessen-landkreise.geojson';
        self::assertFileExists($configPath);
        self::assertFileExists($geoJsonPath);
        $this->factory = new CatalogOrientationMapFactory($configPath, $geoJsonPath);
    }

    public function testDispatchAreaResolvesKnownHessenKey(): void
    {
        $map = $this->factory->forDispatchArea('Frankfurt');

        self::assertTrue($map->enabled);
        self::assertSame('frankfurt', $map->highlightKey);
        self::assertFalse($map->showAllAreas);
    }

    public function testDispatchAreaIsDisabledForUnknownName(): void
    {
        $map = $this->factory->forDispatchArea('Unknown Area XYZ');

        self::assertFalse($map->enabled);
        self::assertNull($map->highlightKey);
    }

    public function testStateEnablesFullHessenMap(): void
    {
        $map = $this->factory->forState('Hessen');

        self::assertTrue($map->enabled);
        self::assertTrue($map->showAllAreas);
        self::assertNull($map->highlightKey);
    }

    public function testStateIsDisabledOutsideHessenPilot(): void
    {
        $map = $this->factory->forState('Bayern');

        self::assertFalse($map->enabled);
    }

    public function testHospitalMapHighlightsDispatchAreaAndKeepsMarker(): void
    {
        $map = $this->factory->forHospital('Frankfurt', 50.1109, 8.6821, 'Uni-Klinik');

        self::assertTrue($map->enabled);
        self::assertSame('frankfurt', $map->highlightKey);
        self::assertTrue($map->hasMarker());
        self::assertSame(50.1109, $map->markerLatitude);
        self::assertSame(8.6821, $map->markerLongitude);
        self::assertSame('Uni-Klinik', $map->markerLabel);
        self::assertFalse($map->showRoute);
        self::assertFalse($map->hasDestinationHighlight());
    }

    public function testHospitalMapWithoutCoordinatesStillShowsArea(): void
    {
        $map = $this->factory->forHospital('Frankfurt', null, null, 'Uni-Klinik');

        self::assertTrue($map->enabled);
        self::assertSame('frankfurt', $map->highlightKey);
        self::assertFalse($map->hasMarker());
        self::assertNull($map->markerLabel);
    }

    public function testHospitalMapIsDisabledForUnknownDispatchArea(): void
    {
        $map = $this->factory->forHospital('Unknown Area XYZ', 50.1, 8.6, 'Klinik');

        self::assertFalse($map->enabled);
        self::assertFalse($map->hasMarker());
    }

    public function testAllocationMapHighlightsOriginAndKeepsHospitalMarker(): void
    {
        $map = $this->factory->forAllocation($this->allocation(
            originName: 'Frankfurt',
            hospitalAreaName: 'Frankfurt',
            latitude: 50.1109,
            longitude: 8.6821,
            hospitalName: 'Uni-Klinik',
        ));

        self::assertTrue($map->enabled);
        self::assertSame('frankfurt', $map->highlightKey);
        self::assertTrue($map->hasMarker());
        self::assertTrue($map->showRoute);
        self::assertSame(50.1109, $map->markerLatitude);
        self::assertSame(8.6821, $map->markerLongitude);
        self::assertSame('Uni-Klinik', $map->markerLabel);
        self::assertFalse($map->hasDestinationHighlight());
        self::assertSame('Kreisfreie Stadt Frankfurt am Main', $map->districtLabel);
    }

    public function testAllocationMapHighlightsOriginIndependentlyOfHospitalDispatchArea(): void
    {
        $map = $this->factory->forAllocation($this->allocation(
            originName: 'Frankfurt',
            hospitalAreaName: 'Offenbach',
            latitude: 50.1,
            longitude: 8.76,
            hospitalName: 'Klinik Offenbach',
        ));

        self::assertTrue($map->enabled);
        self::assertSame('frankfurt', $map->highlightKey);
        self::assertSame('offenbach', $map->destinationHighlightKey);
        self::assertTrue($map->showRoute);
        self::assertTrue($map->hasMarker());
    }

    public function testAllocationMapWithoutHospitalCoordinatesStillShowsOriginArea(): void
    {
        $map = $this->factory->forAllocation($this->allocation(
            originName: 'Frankfurt',
            hospitalAreaName: 'Frankfurt',
        ));

        self::assertTrue($map->enabled);
        self::assertSame('frankfurt', $map->highlightKey);
        self::assertFalse($map->hasMarker());
        self::assertFalse($map->showRoute);
        self::assertNull($map->markerLabel);
        self::assertSame('Kreisfreie Stadt Frankfurt am Main', $map->districtLabel);
    }

    public function testAllocationMapIsDisabledForUnknownOriginDispatchArea(): void
    {
        $map = $this->factory->forAllocation($this->allocation(
            originName: 'Unknown Area XYZ',
            hospitalAreaName: 'Frankfurt',
            latitude: 50.1,
            longitude: 8.6,
        ));

        self::assertFalse($map->enabled);
        self::assertFalse($map->hasMarker());
        self::assertFalse($map->showRoute);
        self::assertNull($map->districtLabel);
    }

    public function testAllocationMapIsDisabledWhenOriginOrHospitalIsMissing(): void
    {
        $hospital = new Hospital()
            ->setName('Klinik')
            ->setLatitude(50.1)
            ->setLongitude(8.6);
        $originOnly = new Allocation()->setDispatchArea(new DispatchArea()->setName('Frankfurt'));
        $hospitalOnly = new Allocation()->setHospital($hospital);

        self::assertFalse($this->factory->forAllocation($originOnly)->enabled);
        self::assertFalse($this->factory->forAllocation($hospitalOnly)->enabled);
    }

    public function testAllocationMapOmitsDestinationHighlightWhenHospitalAreaIsUnmapped(): void
    {
        $map = $this->factory->forAllocation($this->allocation(
            originName: 'Frankfurt',
            hospitalAreaName: 'Unknown Area XYZ',
            latitude: 50.1,
            longitude: 8.6,
        ));

        self::assertTrue($map->enabled);
        self::assertSame('frankfurt', $map->highlightKey);
        self::assertTrue($map->showRoute);
        self::assertTrue($map->hasMarker());
        self::assertFalse($map->hasDestinationHighlight());
        self::assertTrue($map->hasDistrictLabel());
    }

    private function allocation(
        string $originName,
        string $hospitalAreaName,
        ?float $latitude = null,
        ?float $longitude = null,
        string $hospitalName = 'Testklinik',
    ): Allocation {
        $origin = new DispatchArea()->setName($originName);
        $hospitalArea = $originName === $hospitalAreaName
            ? $origin
            : new DispatchArea()->setName($hospitalAreaName);

        $hospital = new Hospital()
            ->setName($hospitalName)
            ->setDispatchArea($hospitalArea)
            ->setLatitude($latitude)
            ->setLongitude($longitude);

        return new Allocation()
            ->setDispatchArea($origin)
            ->setHospital($hospital);
    }
}
