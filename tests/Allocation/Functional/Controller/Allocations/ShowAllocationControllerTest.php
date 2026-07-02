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
        $allocationId = $allocation->getId();
        self::assertNotNull($allocationId);

        self::ensureKernelShutdown();

        $client = $this->createClientAsParticipant();
        $client->enableProfiler();

        $client->request(Request::METHOD_GET, '/explore/allocation/'.$allocationId);

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
        $client->request(Request::METHOD_GET, '/explore/allocation/'.$allocation->getId());

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

        $client->request(Request::METHOD_GET, '/explore/allocation/'.$allocation->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.department-line [data-testid="department-was-closed-indicator"]');
        self::assertSelectorTextContains('.department-line', 'Closed');
    }

    public function testShowRejectsPostMethod(): void
    {
        $client = $this->createClientAsParticipant();
        $client->request(Request::METHOD_POST, '/explore/allocation/1');

        self::assertResponseStatusCodeSame(405);
    }

    public function testShow404ForUnknownAllocation(): void
    {
        $client = $this->createClientAsParticipant();
        $client->request(Request::METHOD_GET, '/explore/allocation/999999');
        self::assertResponseStatusCodeSame(404);
    }
}
