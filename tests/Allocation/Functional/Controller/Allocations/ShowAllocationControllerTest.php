<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Allocations;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\AddressFactory;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssessmentFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Allocation\Infrastructure\Geo\HospitalIsochroneFileStore;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ShowAllocationControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;

    /** Baseline with eager-loaded allocation graph: ~12 queries (1 allocation + shared layout); pre-fix N+1 path was 27+. */
    private const int MAX_QUERIES = 15;

    public function testShowUsesBoundedQueryCount(): void
    {
        self::bootKernel();

        $owner = UserFactory::createOne(['username' => 'owner-user']);
        $createdBy = UserFactory::createOne(['username' => 'area-user']);
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Dispatch Area', 'state' => $state]);
        $address = AddressFactory::new([
            'street' => 'Fake Street 123',
            'postalCode' => '12345',
            'city' => 'Teststadt',
            'state' => 'Hessen',
            'country' => 'DE',
        ])->create();
        $department = DepartmentFactory::createOne(['name' => 'Test Department']);
        $speciality = SpecialityFactory::createOne(['name' => 'Test Speciality']);
        $assignment = AssignmentFactory::createOne();
        $indicationRaw = IndicationRawFactory::createOne(['name' => 'Primary Raw', 'code' => 1001]);
        $indicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'Primary Norm', 'code' => 2001]);
        $secondaryIndicationRaw = IndicationRawFactory::createOne(['name' => 'Secondary Raw', 'code' => 1002]);
        $secondaryIndicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'Secondary Norm', 'code' => 2002]);

        $hospital = HospitalFactory::createOne([
            'name' => 'St. Test Hospital',
            'beds' => 321,
            'address' => $address,
            'state' => $state,
            'dispatchArea' => $dispatch,
            'location' => HospitalLocation::cases()[0],
            'size' => HospitalSize::cases()[0],
            'tier' => HospitalTier::cases()[0],
            'createdBy' => $createdBy,
            'owner' => $owner,
        ]);

        $import = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $createdBy,
        ]);
        $allocation = AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'dispatchArea' => $dispatch,
            'state' => $state,
            'assignment' => $assignment,
            'department' => $department,
            'speciality' => $speciality,
            'indicationRaw' => $indicationRaw,
            'indicationNormalized' => $indicationNormalized,
            'secondaryIndicationRaw' => $secondaryIndicationRaw,
            'secondaryIndicationNormalized' => $secondaryIndicationNormalized,
            'secondaryTransport' => SecondaryTransportFactory::createOne(),
            'infection' => InfectionFactory::createOne(['name' => 'MRSA']),
            'occasion' => OccasionFactory::createOne(),
            'assessment' => AssessmentFactory::createOne(),
        ]);
        $allocationPublicId = $allocation->getPublicIdString();

        self::ensureKernelShutdown();

        $client = $this->createClientAsParticipant();
        $client->enableProfiler();

        $client->request(Request::METHOD_GET, '/explore/allocation/'.$allocationPublicId);

        self::assertResponseIsSuccessful();

        $profile = $client->getProfile();
        self::assertNotNull($profile);

        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);
        $queryCount = $collector->getQueryCount();
        self::assertLessThanOrEqual(
            self::MAX_QUERIES,
            $queryCount,
            sprintf('Expected at most %d DB queries on allocation show path, got %d.', self::MAX_QUERIES, $queryCount),
        );
    }

    public function testShowDisplaysHospitalDetails(): void
    {
        // Arrange
        $client = $this->createClientAsParticipant();

        $owner = UserFactory::createOne(['username' => 'owner-user']);
        $createdBy = UserFactory::createOne(['username' => 'area-user']);
        $stateName = 'Hessen';
        $state = StateFactory::createOne(['name' => $stateName]);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Dispatch Area', 'state' => $state]);
        $address = AddressFactory::new([
            'street' => 'Fake Street 123',
            'postalCode' => '12345',
            'city' => 'Teststadt',
            'state' => $stateName,
            'country' => 'DE',
        ])->create();

        $department = DepartmentFactory::createOne(['name' => 'Test Department']);
        $speciality = SpecialityFactory::createOne(['name' => 'Test Speciality']);
        $assignment = AssignmentFactory::createOne();
        $indicationRaw = IndicationRawFactory::createOne(['name' => 'Test Indication']);
        $indicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'Test Indication']);

        $hospital = HospitalFactory::createOne([
            'name' => 'St. Test Hospital',
            'beds' => 321,
            'address' => $address,
            'state' => $state,
            'dispatchArea' => $dispatch,
            'location' => HospitalLocation::cases()[0],
            'size' => HospitalSize::cases()[0],
            'tier' => HospitalTier::cases()[0],
            'createdBy' => $createdBy,
            'owner' => $owner,
        ]);

        $import = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $createdBy,
        ]);
        $allocation = AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'dispatchArea' => $dispatch,
            'state' => $state,
            'assignment' => $assignment,
            'department' => $department,
            'speciality' => $speciality,
            'indicationRaw' => $indicationRaw,
            'indicationNormalized' => $indicationNormalized,
            'secondaryTransport' => null,
            'infection' => null,
            'occasion' => OccasionFactory::createOne(),
            'assessment' => null,
            'age' => '99',
            'gender' => AllocationGender::MALE,
            'createdAt' => new \DateTimeImmutable('2025-01-02 03:04:05'),
            'arrivalAt' => new \DateTimeImmutable('2025-02-02 03:15:05'),
        ]);

        // Act
        $client->request(Request::METHOD_GET, '/explore/allocation/'.$allocation->getPublicIdString());

        // Assert
        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains('#allocation-id', '#'.$allocation->getId());
        self::assertSelectorTextContains('#allocation-created-at', '02.01.2025');
        self::assertSelectorTextContains('#allocation-arrival-at', '02.02.2025');
        self::assertSelectorTextContains('#allocation-age', '99');
        self::assertSelectorTextContains('#allocation-gender', 'Male');
        self::assertSelectorTextContains('.department-line', 'Test Department');
        self::assertSelectorTextContains('.department-line', 'Test Speciality');

        $pageText = $client->getCrawler()->text();
        self::assertStringContainsString('St. Test Hospital', $pageText);
        self::assertStringContainsString('Teststadt', $pageText);
        self::assertStringContainsString($stateName, $pageText);
        self::assertStringContainsString('321', $pageText);
        self::assertStringContainsString(HospitalSize::cases()[0]->value, $pageText);
        self::assertStringContainsString(HospitalLocation::cases()[0]->value, $pageText);
        self::assertStringContainsString(HospitalTier::cases()[0]->value, $pageText);

        self::assertSelectorExists('[data-testid="allocation-origin-destination"]');
        self::assertSelectorExists('[data-testid="allocation-origin"]');
        self::assertSelectorExists('[data-testid="allocation-destination"]');
        self::assertSelectorExists('[data-testid="allocation-destination-profile"]');
        self::assertSelectorTextContains('[data-testid="allocation-destination-profile"]', HospitalLocation::cases()[0]->value);
        self::assertSelectorTextContains('[data-testid="allocation-destination-profile"]', HospitalTier::cases()[0]->value);
        self::assertSelectorTextContains('[data-testid="allocation-destination-profile"]', HospitalSize::cases()[0]->value);
        self::assertSelectorTextContains('[data-testid="allocation-destination-profile"]', '321');
        self::assertSelectorNotExists('[data-testid="catalog-orientation-map"]');
        self::assertSelectorNotExists('[data-testid="allocation-origin-district"]');
        self::assertSelectorNotExists('[data-testid="allocation-cross-district"]');

        self::assertSelectorExists('a[href="/explore/hospital/'.$hospital->getPublicIdString().'"]');
        self::assertSelectorExists('a[href="/explore/dispatch_area/'.$dispatch->getPublicIdString().'"]');
        self::assertSelectorExists('a[href="/explore/state/'.$state->getPublicIdString().'"]');
        self::assertSelectorExists('a[href="/explore/department/'.$department->getPublicIdString().'"]');
        self::assertSelectorExists('a[href="/explore/speciality/'.$speciality->getPublicIdString().'"]');
        self::assertSelectorExists('a[href="/explore/indication/'.$indicationNormalized->getPublicIdString().'"]');
        self::assertSelectorExists('a[href="/explore/assignment/'.$assignment->getPublicIdString().'"]');
        $occasion = $allocation->getOccasion();
        self::assertNotNull($occasion);
        self::assertSelectorExists('a[href="/explore/occasion/'.$occasion->getPublicIdString().'"]');
        self::assertSelectorNotExists('a[href*="/explore/indication/raw/"]');
    }

    public function testShowLinksOptionalRelatedExplorerEntities(): void
    {
        $client = $this->createClientAsParticipant();

        $owner = UserFactory::createOne(['username' => 'owner-user']);
        $createdBy = UserFactory::createOne(['username' => 'area-user']);
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Dispatch Area', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'state' => $state,
            'dispatchArea' => $dispatch,
            'createdBy' => $createdBy,
            'owner' => $owner,
        ]);
        $import = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $createdBy,
        ]);
        $secondaryIndicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'Secondary Norm']);
        $secondaryTransport = SecondaryTransportFactory::createOne();
        $infection = InfectionFactory::createOne(['name' => 'MRSA']);
        $allocation = AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'dispatchArea' => $dispatch,
            'state' => $state,
            'assignment' => AssignmentFactory::createOne(),
            'department' => DepartmentFactory::createOne(),
            'speciality' => SpecialityFactory::createOne(),
            'indicationRaw' => IndicationRawFactory::createOne(),
            'indicationNormalized' => IndicationNormalizedFactory::createOne(),
            'secondaryIndicationRaw' => IndicationRawFactory::createOne(),
            'secondaryIndicationNormalized' => $secondaryIndicationNormalized,
            'secondaryTransport' => $secondaryTransport,
            'infection' => $infection,
            'occasion' => OccasionFactory::createOne(),
        ]);

        $client->request(Request::METHOD_GET, '/explore/allocation/'.$allocation->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/explore/indication/'.$secondaryIndicationNormalized->getPublicIdString().'"]');
        self::assertSelectorExists('a[href="/explore/secondary_transport/'.$secondaryTransport->getPublicIdString().'"]');
        self::assertSelectorExists('a[href="/explore/infection/'.$infection->getPublicIdString().'"]');
        self::assertSelectorNotExists('a[href*="/explore/indication/raw/"]');
    }

    public function testShowDisplaysDepartmentWasClosedIndicator(): void
    {
        $client = $this->createClientAsParticipant();

        $owner = UserFactory::createOne(['username' => 'owner-user']);
        $createdBy = UserFactory::createOne(['username' => 'area-user']);
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Dispatch Area', 'state' => $state]);
        $department = DepartmentFactory::createOne(['name' => 'Closed Department']);
        $speciality = SpecialityFactory::createOne(['name' => 'Closed Speciality']);
        $assignment = AssignmentFactory::createOne();
        $indicationRaw = IndicationRawFactory::createOne(['name' => 'Test Indication']);
        $indicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'Test Indication']);
        $hospital = HospitalFactory::createOne([
            'state' => $state,
            'dispatchArea' => $dispatch,
            'createdBy' => $createdBy,
            'owner' => $owner,
        ]);
        $import = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $createdBy,
        ]);
        $allocation = AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'dispatchArea' => $dispatch,
            'state' => $state,
            'assignment' => $assignment,
            'department' => $department,
            'speciality' => $speciality,
            'indicationRaw' => $indicationRaw,
            'indicationNormalized' => $indicationNormalized,
            'occasion' => OccasionFactory::createOne(),
            'departmentWasClosed' => true,
        ]);

        $client->request(Request::METHOD_GET, '/explore/allocation/'.$allocation->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.department-line [data-testid="department-was-closed-indicator"]');
        self::assertSelectorTextContains('.department-line', 'Closed');
    }

    public function testShowHandlesMissingTransportType(): void
    {
        $client = $this->createClientAsParticipant();

        $owner = UserFactory::createOne(['username' => 'owner-user']);
        $createdBy = UserFactory::createOne(['username' => 'area-user']);
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Dispatch Area', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'state' => $state,
            'dispatchArea' => $dispatch,
            'createdBy' => $createdBy,
            'owner' => $owner,
        ]);
        $import = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $createdBy,
        ]);
        $allocation = AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'dispatchArea' => $dispatch,
            'state' => $state,
            'assignment' => AssignmentFactory::createOne(),
            'department' => DepartmentFactory::createOne(['name' => 'Optional Transport Department']),
            'speciality' => SpecialityFactory::createOne(['name' => 'Optional Transport Speciality']),
            'indicationRaw' => IndicationRawFactory::createOne(['name' => 'Optional Transport Indication']),
            'indicationNormalized' => IndicationNormalizedFactory::createOne(['name' => 'Optional Transport Indication']),
            'occasion' => OccasionFactory::createOne(),
            'transportType' => null,
        ]);

        $client->request(Request::METHOD_GET, '/explore/allocation/'.$allocation->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="allocation-transport-type"]', 'Unknown');
    }

    public function testShowReturns403ForRoleUserWithoutParticipantRole(): void
    {
        $client = $this->createClientAsRoleUser();

        $owner = UserFactory::createOne(['username' => 'owner-user']);
        $createdBy = UserFactory::createOne(['username' => 'area-user']);
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Dispatch Area', 'state' => $state]);
        $department = DepartmentFactory::createOne(['name' => 'Test Department']);
        $speciality = SpecialityFactory::createOne(['name' => 'Test Speciality']);
        $assignment = AssignmentFactory::createOne();
        $indicationRaw = IndicationRawFactory::createOne(['name' => 'Test Indication']);
        $indicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'Test Indication']);
        $hospital = HospitalFactory::createOne([
            'state' => $state,
            'dispatchArea' => $dispatch,
            'createdBy' => $createdBy,
            'owner' => $owner,
        ]);
        $import = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $createdBy,
        ]);
        $allocation = AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'dispatchArea' => $dispatch,
            'state' => $state,
            'assignment' => $assignment,
            'department' => $department,
            'speciality' => $speciality,
            'indicationRaw' => $indicationRaw,
            'indicationNormalized' => $indicationNormalized,
            'occasion' => OccasionFactory::createOne(),
        ]);

        $client->request(Request::METHOD_GET, '/explore/allocation/'.$allocation->getPublicIdString());

        self::assertResponseStatusCodeSame(403);
    }

    public function testShowRejectsPostMethod(): void
    {
        $client = $this->createClientAsParticipant();
        $client->request(Request::METHOD_POST, '/explore/allocation/00000000-0000-4000-8000-000000000000');

        self::assertResponseStatusCodeSame(405);
    }

    public function testShowDisplaysOriginDestinationMapForMappedHessenDispatchArea(): void
    {
        $client = $this->createClientAsParticipant();

        $owner = UserFactory::createOne(['username' => 'owner-user']);
        $createdBy = UserFactory::createOne(['username' => 'area-user']);
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Frankfurt', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Uni-Klinik',
            'state' => $state,
            'dispatchArea' => $dispatch,
            'latitude' => 50.1109,
            'longitude' => 8.6821,
            'createdBy' => $createdBy,
            'owner' => $owner,
        ]);
        $import = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $createdBy,
        ]);
        $allocation = AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'dispatchArea' => $dispatch,
            'state' => $state,
            'assignment' => AssignmentFactory::createOne(),
            'department' => DepartmentFactory::createOne(),
            'speciality' => SpecialityFactory::createOne(),
            'indicationRaw' => IndicationRawFactory::createOne(),
            'indicationNormalized' => IndicationNormalizedFactory::createOne(),
            'occasion' => OccasionFactory::createOne(),
        ]);

        $crawler = $client->request(Request::METHOD_GET, '/explore/allocation/'.$allocation->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="allocation-origin-destination"]');
        self::assertSelectorTextContains('[data-testid="allocation-origin-district"]', 'Kreisfreie Stadt Frankfurt am Main');
        self::assertSelectorExists('[data-testid="catalog-orientation-map"]');
        self::assertSelectorNotExists('[data-testid="allocation-cross-district"]');

        $map = $crawler->filter('[data-testid="catalog-orientation-map"]');
        self::assertSame('frankfurt', $map->attr('data-catalog-orientation-map-highlight-key-value'));
        self::assertSame('50.1109', $map->attr('data-catalog-orientation-map-marker-lat-value'));
        self::assertSame('8.6821', $map->attr('data-catalog-orientation-map-marker-lng-value'));
        self::assertSame('true', $map->attr('data-catalog-orientation-map-show-route-value'));
        self::assertNull($map->attr('data-catalog-orientation-map-destination-highlight-key-value'));
        self::assertNull($map->attr('data-catalog-orientation-map-origin-label-value'));
        self::assertNull($map->attr('data-catalog-orientation-map-context-markers-value'));
        self::assertSelectorExists('.catalog-orientation-map-legend');
        self::assertCount(2, $crawler->filter('.catalog-orientation-map-legend > li'));
        self::assertSelectorNotExists('.catalog-orientation-map-legend-swatch-context');
        self::assertSelectorExists('.catalog-orientation-map-legend.row');
        self::assertNull($map->attr('data-catalog-orientation-map-isochrones-value'));
        self::assertNull($map->attr('data-catalog-orientation-map-recorded-travel-minutes-value'));
    }

    public function testShowDisplaysOtherHospitalsOnMapForSecondaryTransport(): void
    {
        $client = $this->createClientAsParticipant();

        $owner = UserFactory::createOne(['username' => 'owner-user']);
        $createdBy = UserFactory::createOne(['username' => 'area-user']);
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $origin = DispatchAreaFactory::createOne(['name' => 'Frankfurt', 'state' => $state]);
        $otherArea = DispatchAreaFactory::createOne(['name' => 'Offenbach', 'state' => $state]);
        $destination = HospitalFactory::createOne([
            'name' => 'Zielklinik',
            'state' => $state,
            'dispatchArea' => $origin,
            'latitude' => 50.1109,
            'longitude' => 8.6821,
            'createdBy' => $createdBy,
            'owner' => $owner,
        ]);
        HospitalFactory::createOne([
            'name' => 'Sendeklinik',
            'state' => $state,
            'dispatchArea' => $origin,
            'latitude' => 50.12,
            'longitude' => 8.69,
            'createdBy' => $createdBy,
            'owner' => $owner,
        ]);
        HospitalFactory::createOne([
            'name' => 'Ohne Koordinaten',
            'state' => $state,
            'dispatchArea' => $origin,
            'latitude' => null,
            'longitude' => null,
            'createdBy' => $createdBy,
            'owner' => $owner,
        ]);
        HospitalFactory::createOne([
            'name' => 'Klinik Offenbach',
            'state' => $state,
            'dispatchArea' => $otherArea,
            'latitude' => 50.1,
            'longitude' => 8.76,
            'createdBy' => $createdBy,
            'owner' => $owner,
        ]);
        $allocation = AllocationFactory::createOne([
            'import' => ImportFactory::createOne([
                'hospital' => $destination,
                'createdBy' => $createdBy,
            ]),
            'hospital' => $destination,
            'dispatchArea' => $origin,
            'state' => $state,
            'assignment' => AssignmentFactory::createOne(),
            'department' => DepartmentFactory::createOne(),
            'speciality' => SpecialityFactory::createOne(),
            'indicationRaw' => IndicationRawFactory::createOne(),
            'indicationNormalized' => IndicationNormalizedFactory::createOne(),
            'occasion' => OccasionFactory::createOne(),
            'secondaryTransport' => SecondaryTransportFactory::createOne(),
        ]);

        $crawler = $client->request(Request::METHOD_GET, '/explore/allocation/'.$allocation->getPublicIdString());

        self::assertResponseIsSuccessful();
        $map = $crawler->filter('[data-testid="catalog-orientation-map"]');
        $encoded = $map->attr('data-catalog-orientation-map-context-markers-value');
        self::assertNotNull($encoded);
        $decoded = json_decode($encoded, true);
        self::assertIsArray($decoded);
        self::assertCount(1, $decoded);
        self::assertSame('Sendeklinik', $decoded[0]['label'] ?? null);
        self::assertEqualsWithDelta(50.12, (float) $decoded[0]['lat'], 0.0001);
        self::assertEqualsWithDelta(8.69, (float) $decoded[0]['lng'], 0.0001);
        self::assertSelectorExists('.catalog-orientation-map-legend-swatch-context');
        self::assertCount(3, $crawler->filter('.catalog-orientation-map-legend > li'));
        self::assertSelectorTextContains('.catalog-orientation-map-hint', 'The sending hospital is not recorded');
    }

    public function testShowDisplaysOriginMapWithoutRouteWhenHospitalHasNoCoordinates(): void
    {
        $client = $this->createClientAsParticipant();

        $owner = UserFactory::createOne(['username' => 'owner-user']);
        $createdBy = UserFactory::createOne(['username' => 'area-user']);
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Frankfurt', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Uni-Klinik',
            'state' => $state,
            'dispatchArea' => $dispatch,
            'latitude' => null,
            'longitude' => null,
            'createdBy' => $createdBy,
            'owner' => $owner,
        ]);
        $allocation = AllocationFactory::createOne([
            'import' => ImportFactory::createOne([
                'hospital' => $hospital,
                'createdBy' => $createdBy,
            ]),
            'hospital' => $hospital,
            'dispatchArea' => $dispatch,
            'state' => $state,
            'assignment' => AssignmentFactory::createOne(),
            'department' => DepartmentFactory::createOne(),
            'speciality' => SpecialityFactory::createOne(),
            'indicationRaw' => IndicationRawFactory::createOne(),
            'indicationNormalized' => IndicationNormalizedFactory::createOne(),
            'occasion' => OccasionFactory::createOne(),
        ]);

        $crawler = $client->request(Request::METHOD_GET, '/explore/allocation/'.$allocation->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="catalog-orientation-map"]');
        self::assertSelectorTextContains('[data-testid="allocation-origin-district"]', 'Kreisfreie Stadt Frankfurt am Main');
        self::assertSelectorNotExists('.catalog-orientation-map-legend');

        $map = $crawler->filter('[data-testid="catalog-orientation-map"]');
        self::assertSame('frankfurt', $map->attr('data-catalog-orientation-map-highlight-key-value'));
        self::assertSame('false', $map->attr('data-catalog-orientation-map-show-route-value'));
        self::assertNull($map->attr('data-catalog-orientation-map-marker-lat-value'));
        self::assertNull($map->attr('data-catalog-orientation-map-destination-highlight-key-value'));
        self::assertNull($map->attr('data-catalog-orientation-map-isochrones-value'));
    }

    public function testShowOmitsOrientationMapWhenOriginDispatchAreaIsUnmapped(): void
    {
        $client = $this->createClientAsParticipant();

        $owner = UserFactory::createOne(['username' => 'owner-user']);
        $createdBy = UserFactory::createOne(['username' => 'area-user']);
        $state = StateFactory::createOne(['name' => 'Bayern']);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Unknown Area XYZ', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'latitude' => 50.1109,
            'longitude' => 8.6821,
            'state' => $state,
            'dispatchArea' => $dispatch,
            'createdBy' => $createdBy,
            'owner' => $owner,
        ]);
        $import = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $createdBy,
        ]);
        $allocation = AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'dispatchArea' => $dispatch,
            'state' => $state,
            'assignment' => AssignmentFactory::createOne(),
            'department' => DepartmentFactory::createOne(),
            'speciality' => SpecialityFactory::createOne(),
            'indicationRaw' => IndicationRawFactory::createOne(),
            'indicationNormalized' => IndicationNormalizedFactory::createOne(),
            'occasion' => OccasionFactory::createOne(),
        ]);

        $client->request(Request::METHOD_GET, '/explore/allocation/'.$allocation->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="allocation-origin-destination"]');
        self::assertSelectorNotExists('[data-testid="catalog-orientation-map"]');
        self::assertSelectorNotExists('[data-testid="allocation-origin-district"]');
    }

    public function testShowHighlightsCrossDistrictOriginOnOrientationMap(): void
    {
        $client = $this->createClientAsParticipant();

        $owner = UserFactory::createOne(['username' => 'owner-user']);
        $createdBy = UserFactory::createOne(['username' => 'area-user']);
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $origin = DispatchAreaFactory::createOne(['name' => 'Frankfurt', 'state' => $state]);
        $hospitalArea = DispatchAreaFactory::createOne(['name' => 'Offenbach', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Klinik Offenbach',
            'state' => $state,
            'dispatchArea' => $hospitalArea,
            'latitude' => 50.1,
            'longitude' => 8.76,
            'createdBy' => $createdBy,
            'owner' => $owner,
        ]);
        $allocation = AllocationFactory::createOne([
            'import' => ImportFactory::createOne([
                'hospital' => $hospital,
                'createdBy' => $createdBy,
            ]),
            'hospital' => $hospital,
            'dispatchArea' => $origin,
            'state' => $state,
            'assignment' => AssignmentFactory::createOne(),
            'department' => DepartmentFactory::createOne(),
            'speciality' => SpecialityFactory::createOne(),
            'indicationRaw' => IndicationRawFactory::createOne(),
            'indicationNormalized' => IndicationNormalizedFactory::createOne(),
            'occasion' => OccasionFactory::createOne(),
        ]);

        $crawler = $client->request(Request::METHOD_GET, '/explore/allocation/'.$allocation->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="allocation-cross-district"]');
        self::assertSelectorExists('a[href="/explore/dispatch_area/'.$origin->getPublicIdString().'"]');
        self::assertSelectorExists('a[href="/explore/dispatch_area/'.$hospitalArea->getPublicIdString().'"]');

        $map = $crawler->filter('[data-testid="catalog-orientation-map"]');
        self::assertSame('frankfurt', $map->attr('data-catalog-orientation-map-highlight-key-value'));
        self::assertSame('offenbach', $map->attr('data-catalog-orientation-map-destination-highlight-key-value'));
        self::assertSame('true', $map->attr('data-catalog-orientation-map-show-route-value'));
        self::assertNull($map->attr('data-catalog-orientation-map-isochrones-value'));
    }

    public function testShowRendersIsochroneBandFromStoredFileForRecordedDuration(): void
    {
        $client = $this->createClientAsParticipant();

        $geojson = [
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'properties' => ['value' => 300],
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[8.6, 50.1], [8.7, 50.1], [8.7, 50.2], [8.6, 50.2], [8.6, 50.1]]],
                    ],
                ],
                [
                    'type' => 'Feature',
                    'properties' => ['value' => 600],
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[8.5, 50.0], [8.8, 50.0], [8.8, 50.3], [8.5, 50.3], [8.5, 50.0]]],
                    ],
                ],
            ],
        ];
        $owner = UserFactory::createOne(['username' => 'owner-user']);
        $createdBy = UserFactory::createOne(['username' => 'area-user']);
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Frankfurt', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Uni-Klinik',
            'state' => $state,
            'dispatchArea' => $dispatch,
            'latitude' => 50.1109,
            'longitude' => 8.6821,
            'createdBy' => $createdBy,
            'owner' => $owner,
        ]);
        $store = self::getContainer()->get(HospitalIsochroneFileStore::class);
        $store->writeForHospital($hospital, $geojson);
        $path = $store->pathFor($hospital);
        $allocation = AllocationFactory::createOne([
            'import' => ImportFactory::createOne([
                'hospital' => $hospital,
                'createdBy' => $createdBy,
            ]),
            'hospital' => $hospital,
            'dispatchArea' => $dispatch,
            'state' => $state,
            'assignment' => AssignmentFactory::createOne(),
            'department' => DepartmentFactory::createOne(),
            'speciality' => SpecialityFactory::createOne(),
            'indicationRaw' => IndicationRawFactory::createOne(),
            'indicationNormalized' => IndicationNormalizedFactory::createOne(),
            'occasion' => OccasionFactory::createOne(),
            'createdAt' => new \DateTimeImmutable('2025-01-01 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-01-01 10:08:00'),
        ]);

        try {
            $crawler = $client->request(Request::METHOD_GET, '/explore/allocation/'.$allocation->getPublicIdString());

            self::assertResponseIsSuccessful();
            $map = $crawler->filter('[data-testid="catalog-orientation-map"]');
            self::assertSame('8', $map->attr('data-catalog-orientation-map-recorded-travel-minutes-value'));
            $encoded = $map->attr('data-catalog-orientation-map-isochrones-value');
            self::assertNotNull($encoded);
            $decoded = json_decode($encoded, true);
            self::assertIsArray($decoded);
            self::assertSame('FeatureCollection', $decoded['type']);
            self::assertCount(1, $decoded['features']);
            self::assertSame(600, $decoded['features'][0]['properties']['value']);
            self::assertSelectorExists('.catalog-orientation-map-legend-swatch-isochrone-band');
            self::assertSelectorExists('.catalog-orientation-map-legend-swatch-isochrone-outside');
            self::assertSelectorNotExists('.catalog-orientation-map-legend-swatch-isochrone');
            self::assertCount(4, $crawler->filter('.catalog-orientation-map-legend > li'));
        } finally {
            if (null !== $path && is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testShow404ForUnknownAllocation(): void
    {
        $client = $this->createClientAsParticipant();
        $client->request(Request::METHOD_GET, '/explore/allocation/00000000-0000-4000-8000-000000000000');
        self::assertResponseStatusCodeSame(404);
    }
}
