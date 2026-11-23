<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Explore\Catalog;

use App\Allocation\Application\Contracts\DispatchAreaGeoIndexInterface;
use App\Allocation\Application\Contracts\HospitalIsochroneProviderInterface;
use App\Allocation\Application\Contracts\HospitalLookupInterface;
use App\Allocation\Application\Explore\Catalog\CatalogOrientationMapFactory;
use App\Allocation\Application\Geo\DTO\DispatchAreaGeoSnapshot;
use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\SecondaryTransport;
use PHPUnit\Framework\TestCase;

final class CatalogOrientationMapFactoryTest extends TestCase
{
    private CatalogOrientationMapFactory $factory;

    private HospitalIsochroneProviderInterface $isochroneProvider;

    private HospitalLookupInterface $hospitalLookup;

    protected function setUp(): void
    {
        $this->isochroneProvider = $this->createStub(HospitalIsochroneProviderInterface::class);
        $this->hospitalLookup = $this->createStub(HospitalLookupInterface::class);
        $this->hospitalLookup->method('findByDispatchArea')->willReturn([]);
        $this->factory = $this->createFactory();
    }

    private function createFactory(
        ?HospitalIsochroneProviderInterface $isochroneProvider = null,
        ?HospitalLookupInterface $hospitalLookup = null,
    ): CatalogOrientationMapFactory {
        $index = $this->createStub(DispatchAreaGeoIndexInterface::class);
        $index->method('snapshot')->willReturn(new DispatchAreaGeoSnapshot([
            '1' => ['state' => 'Hessen', 'districts' => ['Kreisfreie Stadt Frankfurt am Main']],
            '2' => ['state' => 'Hessen', 'districts' => ['Offenbach am Main', 'Offenbach']],
            '3' => ['state' => 'Niedersachsen', 'districts' => ['Göttingen']],
        ]));

        return new CatalogOrientationMapFactory(
            $index,
            $isochroneProvider ?? $this->isochroneProvider,
            $hospitalLookup ?? $this->hospitalLookup,
        );
    }

    public function testDispatchAreaResolvesKnownHessenKey(): void
    {
        $map = $this->factory->forDispatchArea(1);

        self::assertTrue($map->enabled);
        self::assertSame('1', $map->highlightKey);
        self::assertFalse($map->showAllAreas);
    }

    public function testDispatchAreaIsDisabledForAnUnknownId(): void
    {
        $map = $this->factory->forDispatchArea(99);

        self::assertFalse($map->enabled);
        self::assertNull($map->highlightKey);
    }

    public function testDispatchAreaIsDisabledWithoutAnId(): void
    {
        self::assertFalse($this->factory->forDispatchArea(null)->enabled);
    }

    public function testStateEnablesFullHessenMap(): void
    {
        $map = $this->factory->forState('Hessen');

        self::assertTrue($map->enabled);
        self::assertTrue($map->showAllAreas);
        self::assertSame('Hessen', $map->stateName);
        self::assertNull($map->highlightKey);
    }

    public function testStateWithNeighbouringAreasShowsOnlyThatState(): void
    {
        $map = $this->factory->forState('niedersachsen');

        self::assertTrue($map->enabled);
        self::assertTrue($map->showAllAreas);
        self::assertSame('Niedersachsen', $map->stateName);
    }

    public function testStateWithoutMappedAreasIsDisabled(): void
    {
        self::assertFalse($this->factory->forState('Berlin')->enabled);
        self::assertFalse($this->factory->forState('Nordrhein-Westfalen')->enabled);
    }

    public function testDispatchAreaIsDisabledWithoutACompleteShape(): void
    {
        self::assertFalse($this->factory->forDispatchArea(98)->enabled);
    }

    public function testStateIsDisabledForEmptyName(): void
    {
        self::assertFalse($this->factory->forState(null)->enabled);
        self::assertFalse($this->factory->forState('')->enabled);
    }

    public function testHospitalMapHighlightsDispatchAreaAndKeepsMarker(): void
    {
        $map = $this->factory->forHospital(1, 50.1109, 8.6821, 'Uni-Klinik');

        self::assertTrue($map->enabled);
        self::assertSame('1', $map->highlightKey);
        self::assertTrue($map->hasMarker());
        self::assertSame(50.1109, $map->markerLatitude);
        self::assertSame(8.6821, $map->markerLongitude);
        self::assertSame('Uni-Klinik', $map->markerLabel);
        self::assertFalse($map->showRoute);
        self::assertFalse($map->hasDestinationHighlight());
    }

    public function testHospitalMapWithoutCoordinatesStillShowsArea(): void
    {
        $map = $this->factory->forHospital(1, null, null, 'Uni-Klinik');

        self::assertTrue($map->enabled);
        self::assertSame('1', $map->highlightKey);
        self::assertFalse($map->hasMarker());
        self::assertNull($map->markerLabel);
    }

    public function testHospitalMapIsDisabledForUnknownDispatchArea(): void
    {
        $map = $this->factory->forHospital(99, 50.1, 8.6, 'Klinik');

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
        self::assertSame('1', $map->highlightKey);
        self::assertTrue($map->hasMarker());
        self::assertTrue($map->showRoute);
        self::assertSame(50.1109, $map->markerLatitude);
        self::assertSame(8.6821, $map->markerLongitude);
        self::assertSame('Uni-Klinik', $map->markerLabel);
        self::assertFalse($map->hasDestinationHighlight());
        self::assertSame('Kreisfreie Stadt Frankfurt am Main', $map->districtLabel);
        self::assertFalse($map->hasContextMarkers());
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
        self::assertSame('1', $map->highlightKey);
        self::assertSame('2', $map->destinationHighlightKey);
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
        self::assertSame('1', $map->highlightKey);
        self::assertFalse($map->hasMarker());
        self::assertFalse($map->showRoute);
        self::assertNull($map->markerLabel);
        self::assertSame('Kreisfreie Stadt Frankfurt am Main', $map->districtLabel);
        self::assertFalse($map->hasIsochrones());
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
        $originOnly = new Allocation()->setDispatchArea($this->area('Frankfurt'));
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
        self::assertSame('1', $map->highlightKey);
        self::assertTrue($map->showRoute);
        self::assertTrue($map->hasMarker());
        self::assertFalse($map->hasDestinationHighlight());
        self::assertTrue($map->hasDistrictLabel());
        self::assertFalse($map->hasIsochrones());
        self::assertNull($map->recordedTravelMinutes);
    }

    public function testAllocationMapAttachesMatchingTravelBandFromStoredCatalog(): void
    {
        $five = [
            'type' => 'Feature',
            'properties' => ['value' => 300],
            'geometry' => ['type' => 'Polygon', 'coordinates' => []],
        ];
        $ten = [
            'type' => 'Feature',
            'properties' => ['value' => 600],
            'geometry' => ['type' => 'Polygon', 'coordinates' => []],
        ];
        $this->isochroneProvider = $this->createStub(HospitalIsochroneProviderInterface::class);
        $this->isochroneProvider->method('findForHospital')->willReturn([
            'type' => 'FeatureCollection',
            'features' => [$five, $ten],
        ]);
        $this->factory = $this->createFactory($this->isochroneProvider);

        $map = $this->factory->forAllocation($this->allocation(
            originName: 'Frankfurt',
            hospitalAreaName: 'Frankfurt',
            latitude: 50.1109,
            longitude: 8.6821,
            hospitalName: 'Uni-Klinik',
            createdAt: new \DateTimeImmutable('2025-01-01 10:00:00'),
            arrivalAt: new \DateTimeImmutable('2025-01-01 10:08:00'),
        ));

        self::assertTrue($map->hasIsochrones());
        self::assertSame([$ten], $map->isochronesGeoJson['features'] ?? null);
        self::assertSame(8, $map->recordedTravelMinutes);
        self::assertFalse($map->recordedTravelExceedsIsochrones());
    }

    public function testAllocationMapOmitsIsochronesWhenStoredCatalogIsMissing(): void
    {
        $this->isochroneProvider = $this->createStub(HospitalIsochroneProviderInterface::class);
        $this->isochroneProvider->method('findForHospital')->willReturn(null);
        $this->factory = $this->createFactory($this->isochroneProvider);

        $map = $this->factory->forAllocation($this->allocation(
            originName: 'Frankfurt',
            hospitalAreaName: 'Frankfurt',
            latitude: 50.1109,
            longitude: 8.6821,
            createdAt: new \DateTimeImmutable('2025-01-01 10:00:00'),
            arrivalAt: new \DateTimeImmutable('2025-01-01 10:08:00'),
        ));

        self::assertFalse($map->hasIsochrones());
        self::assertSame(8, $map->recordedTravelMinutes);
    }

    public function testAllocationMapDoesNotReadIsochronesWithoutHospitalCoordinates(): void
    {
        $provider = $this->createMock(HospitalIsochroneProviderInterface::class);
        $provider->expects(self::never())->method('findForHospital');
        $factory = $this->createFactory($provider);

        $map = $factory->forAllocation($this->allocation(
            originName: 'Frankfurt',
            hospitalAreaName: 'Frankfurt',
        ));

        self::assertFalse($map->hasIsochrones());
        self::assertNull($map->recordedTravelMinutes);
    }

    public function testAllocationMapDoesNotLoadContextHospitalsWithoutSecondaryTransport(): void
    {
        $lookup = $this->createMock(HospitalLookupInterface::class);
        $lookup->expects(self::never())->method('findByDispatchArea');
        $factory = $this->createFactory(hospitalLookup: $lookup);

        $map = $factory->forAllocation($this->allocation(
            originName: 'Frankfurt',
            hospitalAreaName: 'Frankfurt',
            latitude: 50.1109,
            longitude: 8.6821,
        ));

        self::assertFalse($map->hasContextMarkers());
    }

    public function testAllocationMapAddsOtherHospitalsInOriginAreaForSecondaryTransport(): void
    {
        $origin = $this->area('Frankfurt');
        $destination = new Hospital()
            ->setName('Zielklinik')
            ->setDispatchArea($origin)
            ->setLatitude(50.1109)
            ->setLongitude(8.6821);
        $other = new Hospital()
            ->setName('Sendeklinik')
            ->setDispatchArea($origin)
            ->setLatitude(50.12)
            ->setLongitude(8.69);
        $withoutCoords = new Hospital()
            ->setName('Ohne Koordinaten')
            ->setDispatchArea($origin);

        $lookup = $this->createStub(HospitalLookupInterface::class);
        $lookup->method('findByDispatchArea')->willReturn([$destination, $other, $withoutCoords]);
        $factory = $this->createFactory(hospitalLookup: $lookup);

        $allocation = new Allocation()
            ->setDispatchArea($origin)
            ->setHospital($destination)
            ->setSecondaryTransport(new SecondaryTransport()->setName('Kapazitätsengpass'));

        $map = $factory->forAllocation($allocation);

        self::assertTrue($map->hasContextMarkers());
        self::assertSame([
            ['lat' => 50.12, 'lng' => 8.69, 'label' => 'Sendeklinik'],
        ], $map->contextMarkers);
    }

    public function testAllocationMapOmitsContextMarkersWhenSecondaryHasNoOtherHospitals(): void
    {
        $allocation = $this->allocation(
            originName: 'Frankfurt',
            hospitalAreaName: 'Frankfurt',
            latitude: 50.1109,
            longitude: 8.6821,
            secondaryTransport: new SecondaryTransport()->setName('Intensivstation'),
        );
        $lookup = $this->createStub(HospitalLookupInterface::class);
        $lookup->method('findByDispatchArea')->willReturn([$allocation->getHospital()]);
        $factory = $this->createFactory(hospitalLookup: $lookup);

        $map = $factory->forAllocation($allocation);

        self::assertFalse($map->hasContextMarkers());
    }

    private function allocation(
        string $originName,
        string $hospitalAreaName,
        ?float $latitude = null,
        ?float $longitude = null,
        string $hospitalName = 'Testklinik',
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $arrivalAt = null,
        ?SecondaryTransport $secondaryTransport = null,
    ): Allocation {
        $origin = $this->area($originName);
        $hospitalArea = $originName === $hospitalAreaName
            ? $origin
            : $this->area($hospitalAreaName);

        $hospital = new Hospital()
            ->setName($hospitalName)
            ->setDispatchArea($hospitalArea)
            ->setLatitude($latitude)
            ->setLongitude($longitude);

        $allocation = new Allocation()
            ->setDispatchArea($origin)
            ->setHospital($hospital);

        if ($createdAt instanceof \DateTimeImmutable) {
            $allocation->setCreatedAt($createdAt);
        }

        if ($arrivalAt instanceof \DateTimeImmutable) {
            $allocation->setArrivalAt($arrivalAt);
        }

        if ($secondaryTransport instanceof SecondaryTransport) {
            $allocation->setSecondaryTransport($secondaryTransport);
        }

        return $allocation;
    }

    private function area(string $name): DispatchArea
    {
        $area = new DispatchArea()->setName($name);
        new \ReflectionProperty(DispatchArea::class, 'id')->setValue($area, ['Frankfurt' => 1, 'Offenbach' => 2][$name] ?? 99);

        return $area;
    }
}
