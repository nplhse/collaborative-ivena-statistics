<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Query;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Allocation\Infrastructure\Query\AllocationBucketQuery;
use App\Allocation\Infrastructure\Repository\AllocationRepository;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AllocationBucketQueryTest extends KernelTestCase
{
    use Factories;

    private AllocationBucketQuery $query;
    private AllocationRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(AllocationBucketQuery::class);
        $this->repository = self::getContainer()->get(AllocationRepository::class);
    }

    public function testBucketByMonthAndGenderInRangeGroupsByMonthAndGender(): void
    {
        [$from, $toExclusive, $import] = $this->march2024FixtureContext();

        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('2024-03-10'),
            'gender' => AllocationGender::MALE,
            'import' => $import,
        ]);
        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('2024-03-15'),
            'gender' => AllocationGender::MALE,
            'import' => $import,
        ]);
        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('2024-03-20'),
            'gender' => AllocationGender::FEMALE,
            'import' => $import,
        ]);

        $buckets = $this->query->bucketByMonthAndGenderInRange($from, $toExclusive);

        self::assertSame(2, $buckets['2024-03']['M']);
        self::assertSame(1, $buckets['2024-03']['F']);
    }

    public function testBucketByMonthAndUrgencyInRangeGroupsByMonthAndUrgency(): void
    {
        [$from, $toExclusive, $import] = $this->march2024FixtureContext();

        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('2024-03-10'),
            'urgency' => AllocationUrgency::EMERGENCY,
            'import' => $import,
        ]);
        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('2024-03-12'),
            'urgency' => AllocationUrgency::INPATIENT,
            'import' => $import,
        ]);

        $buckets = $this->query->bucketByMonthAndUrgencyInRange($from, $toExclusive);

        $this->assertNestedBucketCount($buckets, '2024-03', (string) AllocationUrgency::EMERGENCY->value, 1);
        $this->assertNestedBucketCount($buckets, '2024-03', (string) AllocationUrgency::INPATIENT->value, 1);
    }

    public function testBucketByMonthResourcesRequiredInRangeCountsFlags(): void
    {
        [$from, $toExclusive, $import] = $this->march2024FixtureContext();

        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('2024-03-10'),
            'requiresCathlab' => true,
            'requiresResus' => false,
            'import' => $import,
        ]);
        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('2024-03-11'),
            'requiresCathlab' => true,
            'requiresResus' => true,
            'import' => $import,
        ]);
        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('2024-03-12'),
            'requiresCathlab' => false,
            'requiresResus' => false,
            'import' => $import,
        ]);

        $buckets = $this->query->bucketByMonthResourcesRequiredInRange($from, $toExclusive);

        self::assertSame(2, $buckets['2024-03']['cathlab']);
        self::assertSame(1, $buckets['2024-03']['resus']);
        self::assertSame(2, $buckets['2024-03']['with_any']);
    }

    public function testBucketByMonthClinicalFeaturesInRangeCountsFlags(): void
    {
        [$from, $toExclusive, $import] = $this->march2024FixtureContext();
        $infection = InfectionFactory::createOne();

        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('2024-03-10'),
            'isWithPhysician' => true,
            'isCPR' => true,
            'isVentilated' => false,
            'isShock' => false,
            'isPregnant' => false,
            'infection' => $infection,
            'import' => $import,
        ]);
        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('2024-03-11'),
            'isWithPhysician' => false,
            'isCPR' => false,
            'isVentilated' => true,
            'isShock' => true,
            'isPregnant' => true,
            'infection' => null,
            'import' => $import,
        ]);

        $buckets = $this->query->bucketByMonthClinicalFeaturesInRange($from, $toExclusive);

        self::assertSame(1, $buckets['2024-03']['with_physician']);
        self::assertSame(1, $buckets['2024-03']['cpr']);
        self::assertSame(1, $buckets['2024-03']['ventilated']);
        self::assertSame(1, $buckets['2024-03']['shock']);
        self::assertSame(1, $buckets['2024-03']['pregnant']);
        self::assertSame(1, $buckets['2024-03']['infectious']);
        self::assertSame(2, $buckets['2024-03']['with_any']);
    }

    public function testCalendarMonthAndDayBucketsUseExpectedKeys(): void
    {
        [$from, $toExclusive, $import] = $this->march2024FixtureContext();

        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('2024-03-10'),
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'requiresCathlab' => true,
            'requiresResus' => false,
            'isWithPhysician' => true,
            'isCPR' => false,
            'isVentilated' => false,
            'isShock' => false,
            'isPregnant' => false,
            'infection' => null,
            'import' => $import,
        ]);

        $emergency = (string) AllocationUrgency::EMERGENCY->value;

        self::assertSame(1, $this->query->bucketByCalendarMonthAndGenderInRange($from, $toExclusive)['cal-03']['M']);
        self::assertSame(1, $this->query->bucketByDayAndGenderInRange($from, $toExclusive)['2024-03-10']['M']);
        $this->assertNestedBucketCount(
            $this->query->bucketByCalendarMonthAndUrgencyInRange($from, $toExclusive),
            'cal-03',
            $emergency,
            1,
        );
        $this->assertNestedBucketCount(
            $this->query->bucketByDayAndUrgencyInRange($from, $toExclusive),
            '2024-03-10',
            $emergency,
            1,
        );
        self::assertSame(1, $this->query->bucketByCalendarMonthResourcesRequiredInRange($from, $toExclusive)['cal-03']['cathlab']);
        self::assertSame(1, $this->query->bucketByDayResourcesRequiredInRange($from, $toExclusive)['2024-03-10']['cathlab']);
        self::assertSame(1, $this->query->bucketByCalendarMonthClinicalFeaturesInRange($from, $toExclusive)['cal-03']['with_physician']);
        self::assertSame(1, $this->query->bucketByDayClinicalFeaturesInRange($from, $toExclusive)['2024-03-10']['with_physician']);
    }

    public function testHospitalScopeFiltersAndEmptyIdsShortCircuit(): void
    {
        [$from, $toExclusive, $import] = $this->march2024FixtureContext();
        $hospitalA = HospitalFactory::createOne();
        $hospitalB = HospitalFactory::createOne();

        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('2024-03-10'),
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'requiresCathlab' => true,
            'requiresResus' => false,
            'isWithPhysician' => true,
            'isCPR' => false,
            'isVentilated' => false,
            'isShock' => false,
            'isPregnant' => false,
            'infection' => null,
            'hospital' => $hospitalA,
            'import' => $import,
        ]);
        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('2024-03-11'),
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'requiresCathlab' => false,
            'requiresResus' => true,
            'isWithPhysician' => false,
            'isCPR' => true,
            'isVentilated' => false,
            'isShock' => false,
            'isPregnant' => false,
            'infection' => null,
            'hospital' => $hospitalB,
            'import' => $import,
        ]);

        $hospitalIds = [(int) $hospitalA->getId()];

        $emergency = (string) AllocationUrgency::EMERGENCY->value;

        self::assertSame(1, $this->query->bucketByMonthAndGenderInRangeForHospitals($from, $toExclusive, $hospitalIds)['2024-03']['M']);
        self::assertArrayNotHasKey('F', $this->query->bucketByMonthAndGenderInRangeForHospitals($from, $toExclusive, $hospitalIds)['2024-03'] ?? []);
        $this->assertNestedBucketCount(
            $this->query->bucketByMonthAndUrgencyInRangeForHospitals($from, $toExclusive, $hospitalIds),
            '2024-03',
            $emergency,
            1,
        );
        self::assertSame(1, $this->query->bucketByMonthResourcesRequiredInRangeForHospitals($from, $toExclusive, $hospitalIds)['2024-03']['cathlab']);
        self::assertSame(1, $this->query->bucketByMonthClinicalFeaturesInRangeForHospitals($from, $toExclusive, $hospitalIds)['2024-03']['with_physician']);
        self::assertSame(1, $this->query->bucketByCalendarMonthAndGenderInRangeForHospitals($from, $toExclusive, $hospitalIds)['cal-03']['M']);
        self::assertSame(1, $this->query->bucketByDayAndGenderInRangeForHospitals($from, $toExclusive, $hospitalIds)['2024-03-10']['M']);
        $this->assertNestedBucketCount(
            $this->query->bucketByCalendarMonthAndUrgencyInRangeForHospitals($from, $toExclusive, $hospitalIds),
            'cal-03',
            $emergency,
            1,
        );
        $this->assertNestedBucketCount(
            $this->query->bucketByDayAndUrgencyInRangeForHospitals($from, $toExclusive, $hospitalIds),
            '2024-03-10',
            $emergency,
            1,
        );
        self::assertSame(1, $this->query->bucketByCalendarMonthResourcesRequiredInRangeForHospitals($from, $toExclusive, $hospitalIds)['cal-03']['cathlab']);
        self::assertSame(1, $this->query->bucketByDayResourcesRequiredInRangeForHospitals($from, $toExclusive, $hospitalIds)['2024-03-10']['cathlab']);
        self::assertSame(1, $this->query->bucketByCalendarMonthClinicalFeaturesInRangeForHospitals($from, $toExclusive, $hospitalIds)['cal-03']['with_physician']);
        self::assertSame(1, $this->query->bucketByDayClinicalFeaturesInRangeForHospitals($from, $toExclusive, $hospitalIds)['2024-03-10']['with_physician']);

        self::assertSame([], $this->query->bucketByMonthAndGenderInRangeForHospitals($from, $toExclusive, []));
        self::assertSame([], $this->query->bucketByMonthAndUrgencyInRangeForHospitals($from, $toExclusive, []));
        self::assertSame([], $this->query->bucketByMonthResourcesRequiredInRangeForHospitals($from, $toExclusive, []));
        self::assertSame([], $this->query->bucketByMonthClinicalFeaturesInRangeForHospitals($from, $toExclusive, []));
        self::assertSame([], $this->query->bucketByCalendarMonthAndGenderInRangeForHospitals($from, $toExclusive, []));
        self::assertSame([], $this->query->bucketByDayAndGenderInRangeForHospitals($from, $toExclusive, []));
        self::assertSame([], $this->query->bucketByCalendarMonthAndUrgencyInRangeForHospitals($from, $toExclusive, []));
        self::assertSame([], $this->query->bucketByDayAndUrgencyInRangeForHospitals($from, $toExclusive, []));
        self::assertSame([], $this->query->bucketByCalendarMonthResourcesRequiredInRangeForHospitals($from, $toExclusive, []));
        self::assertSame([], $this->query->bucketByDayResourcesRequiredInRangeForHospitals($from, $toExclusive, []));
        self::assertSame([], $this->query->bucketByCalendarMonthClinicalFeaturesInRangeForHospitals($from, $toExclusive, []));
        self::assertSame([], $this->query->bucketByDayClinicalFeaturesInRangeForHospitals($from, $toExclusive, []));
        self::assertSame([], $this->query->bucketByMonthAndGenderLast12MonthsForHospitals([]));
        self::assertSame([], $this->query->bucketByMonthAndUrgencyLast12MonthsForHospitals([]));
        self::assertSame([], $this->query->bucketByMonthResourcesRequiredLast12MonthsForHospitals([]));
        self::assertSame([], $this->query->bucketByMonthClinicalFeaturesLast12MonthsForHospitals([]));
    }

    public function testLast12MonthsWrappersIncludeAllocationsInWindow(): void
    {
        $import = $this->seedAllocationGraph();
        $hospital = HospitalFactory::createOne();
        $inWindow = new \DateTimeImmutable('first day of this month')->modify('+5 days')->setTime(12, 0, 0);
        $ym = $inWindow->format('Y-m');

        AllocationFactory::createOne([
            'createdAt' => $inWindow,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'requiresCathlab' => true,
            'requiresResus' => false,
            'isWithPhysician' => true,
            'isCPR' => false,
            'isVentilated' => false,
            'isShock' => false,
            'isPregnant' => false,
            'infection' => null,
            'hospital' => $hospital,
            'import' => $import,
        ]);

        $hospitalIds = [(int) $hospital->getId()];

        $emergency = (string) AllocationUrgency::EMERGENCY->value;

        self::assertSame(1, $this->query->bucketByMonthAndGenderLast12Months()[$ym]['M']);
        self::assertSame(1, $this->query->bucketByMonthAndGenderLast12MonthsForHospitals($hospitalIds)[$ym]['M']);
        $this->assertNestedBucketCount($this->query->bucketByMonthAndUrgencyLast12Months(), $ym, $emergency, 1);
        $this->assertNestedBucketCount(
            $this->query->bucketByMonthAndUrgencyLast12MonthsForHospitals($hospitalIds),
            $ym,
            $emergency,
            1,
        );
        self::assertSame(1, $this->query->bucketByMonthResourcesRequiredLast12Months()[$ym]['cathlab']);
        self::assertSame(1, $this->query->bucketByMonthResourcesRequiredLast12MonthsForHospitals($hospitalIds)[$ym]['cathlab']);
        self::assertSame(1, $this->query->bucketByMonthClinicalFeaturesLast12Months()[$ym]['with_physician']);
        self::assertSame(1, $this->query->bucketByMonthClinicalFeaturesLast12MonthsForHospitals($hospitalIds)[$ym]['with_physician']);
    }

    public function testRepositoryDelegatesMatchQueryResults(): void
    {
        [$from, $toExclusive, $import] = $this->march2024FixtureContext();
        $hospital = HospitalFactory::createOne();
        $hospitalIds = [(int) $hospital->getId()];
        $inWindow = new \DateTimeImmutable('first day of this month')->modify('+5 days')->setTime(12, 0, 0);

        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('2024-03-10'),
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'requiresCathlab' => true,
            'requiresResus' => false,
            'isWithPhysician' => true,
            'isCPR' => false,
            'isVentilated' => false,
            'isShock' => false,
            'isPregnant' => false,
            'infection' => null,
            'hospital' => $hospital,
            'import' => $import,
        ]);
        AllocationFactory::createOne([
            'createdAt' => $inWindow,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'requiresCathlab' => false,
            'requiresResus' => true,
            'isWithPhysician' => false,
            'isCPR' => true,
            'isVentilated' => false,
            'isShock' => false,
            'isPregnant' => false,
            'infection' => null,
            'hospital' => $hospital,
            'import' => $import,
        ]);

        self::assertSame(
            $this->query->bucketByMonthAndGenderLast12Months(),
            $this->repository->bucketAllocationsByMonthAndGenderLast12Months(),
        );
        self::assertSame(
            $this->query->bucketByMonthAndGenderLast12MonthsForHospitals($hospitalIds),
            $this->repository->bucketAllocationsByMonthAndGenderLast12MonthsForHospitals($hospitalIds),
        );
        self::assertSame(
            $this->query->bucketByMonthAndGenderInRange($from, $toExclusive),
            $this->repository->bucketAllocationsByMonthAndGenderInRange($from, $toExclusive),
        );
        self::assertSame(
            $this->query->bucketByMonthAndGenderInRangeForHospitals($from, $toExclusive, $hospitalIds),
            $this->repository->bucketAllocationsByMonthAndGenderInRangeForHospitals($from, $toExclusive, $hospitalIds),
        );
        self::assertSame(
            $this->query->bucketByMonthAndUrgencyLast12Months(),
            $this->repository->bucketAllocationsByMonthAndUrgencyLast12Months(),
        );
        self::assertSame(
            $this->query->bucketByMonthAndUrgencyLast12MonthsForHospitals($hospitalIds),
            $this->repository->bucketAllocationsByMonthAndUrgencyLast12MonthsForHospitals($hospitalIds),
        );
        self::assertSame(
            $this->query->bucketByMonthAndUrgencyInRange($from, $toExclusive),
            $this->repository->bucketAllocationsByMonthAndUrgencyInRange($from, $toExclusive),
        );
        self::assertSame(
            $this->query->bucketByMonthAndUrgencyInRangeForHospitals($from, $toExclusive, $hospitalIds),
            $this->repository->bucketAllocationsByMonthAndUrgencyInRangeForHospitals($from, $toExclusive, $hospitalIds),
        );
        self::assertSame(
            $this->query->bucketByMonthResourcesRequiredLast12Months(),
            $this->repository->bucketAllocationsByMonthResourcesRequiredLast12Months(),
        );
        self::assertSame(
            $this->query->bucketByMonthResourcesRequiredLast12MonthsForHospitals($hospitalIds),
            $this->repository->bucketAllocationsByMonthResourcesRequiredLast12MonthsForHospitals($hospitalIds),
        );
        self::assertSame(
            $this->query->bucketByMonthResourcesRequiredInRange($from, $toExclusive),
            $this->repository->bucketAllocationsByMonthResourcesRequiredInRange($from, $toExclusive),
        );
        self::assertSame(
            $this->query->bucketByMonthResourcesRequiredInRangeForHospitals($from, $toExclusive, $hospitalIds),
            $this->repository->bucketAllocationsByMonthResourcesRequiredInRangeForHospitals($from, $toExclusive, $hospitalIds),
        );
        self::assertSame(
            $this->query->bucketByMonthClinicalFeaturesLast12Months(),
            $this->repository->bucketAllocationsByMonthClinicalFeaturesLast12Months(),
        );
        self::assertSame(
            $this->query->bucketByMonthClinicalFeaturesLast12MonthsForHospitals($hospitalIds),
            $this->repository->bucketAllocationsByMonthClinicalFeaturesLast12MonthsForHospitals($hospitalIds),
        );
        self::assertSame(
            $this->query->bucketByMonthClinicalFeaturesInRange($from, $toExclusive),
            $this->repository->bucketAllocationsByMonthClinicalFeaturesInRange($from, $toExclusive),
        );
        self::assertSame(
            $this->query->bucketByMonthClinicalFeaturesInRangeForHospitals($from, $toExclusive, $hospitalIds),
            $this->repository->bucketAllocationsByMonthClinicalFeaturesInRangeForHospitals($from, $toExclusive, $hospitalIds),
        );
        self::assertSame(
            $this->query->bucketByCalendarMonthAndGenderInRange($from, $toExclusive),
            $this->repository->bucketAllocationsByCalendarMonthOfYearAndGenderInRange($from, $toExclusive),
        );
        self::assertSame(
            $this->query->bucketByCalendarMonthAndGenderInRangeForHospitals($from, $toExclusive, $hospitalIds),
            $this->repository->bucketAllocationsByCalendarMonthOfYearAndGenderInRangeForHospitals($from, $toExclusive, $hospitalIds),
        );
        self::assertSame(
            $this->query->bucketByDayAndGenderInRange($from, $toExclusive),
            $this->repository->bucketAllocationsByDayAndGenderInRange($from, $toExclusive),
        );
        self::assertSame(
            $this->query->bucketByDayAndGenderInRangeForHospitals($from, $toExclusive, $hospitalIds),
            $this->repository->bucketAllocationsByDayAndGenderInRangeForHospitals($from, $toExclusive, $hospitalIds),
        );
        self::assertSame(
            $this->query->bucketByCalendarMonthAndUrgencyInRange($from, $toExclusive),
            $this->repository->bucketAllocationsByCalendarMonthOfYearAndUrgencyInRange($from, $toExclusive),
        );
        self::assertSame(
            $this->query->bucketByCalendarMonthAndUrgencyInRangeForHospitals($from, $toExclusive, $hospitalIds),
            $this->repository->bucketAllocationsByCalendarMonthOfYearAndUrgencyInRangeForHospitals($from, $toExclusive, $hospitalIds),
        );
        self::assertSame(
            $this->query->bucketByDayAndUrgencyInRange($from, $toExclusive),
            $this->repository->bucketAllocationsByDayAndUrgencyInRange($from, $toExclusive),
        );
        self::assertSame(
            $this->query->bucketByDayAndUrgencyInRangeForHospitals($from, $toExclusive, $hospitalIds),
            $this->repository->bucketAllocationsByDayAndUrgencyInRangeForHospitals($from, $toExclusive, $hospitalIds),
        );
        self::assertSame(
            $this->query->bucketByCalendarMonthResourcesRequiredInRange($from, $toExclusive),
            $this->repository->bucketAllocationsByCalendarMonthResourcesRequiredInRange($from, $toExclusive),
        );
        self::assertSame(
            $this->query->bucketByCalendarMonthResourcesRequiredInRangeForHospitals($from, $toExclusive, $hospitalIds),
            $this->repository->bucketAllocationsByCalendarMonthResourcesRequiredInRangeForHospitals($from, $toExclusive, $hospitalIds),
        );
        self::assertSame(
            $this->query->bucketByDayResourcesRequiredInRange($from, $toExclusive),
            $this->repository->bucketAllocationsByDayResourcesRequiredInRange($from, $toExclusive),
        );
        self::assertSame(
            $this->query->bucketByDayResourcesRequiredInRangeForHospitals($from, $toExclusive, $hospitalIds),
            $this->repository->bucketAllocationsByDayResourcesRequiredInRangeForHospitals($from, $toExclusive, $hospitalIds),
        );
        self::assertSame(
            $this->query->bucketByCalendarMonthClinicalFeaturesInRange($from, $toExclusive),
            $this->repository->bucketAllocationsByCalendarMonthClinicalFeaturesInRangeAggregated($from, $toExclusive),
        );
        self::assertSame(
            $this->query->bucketByCalendarMonthClinicalFeaturesInRangeForHospitals($from, $toExclusive, $hospitalIds),
            $this->repository->bucketAllocationsByCalendarMonthClinicalFeaturesInRangeAggregatedForHospitals($from, $toExclusive, $hospitalIds),
        );
        self::assertSame(
            $this->query->bucketByDayClinicalFeaturesInRange($from, $toExclusive),
            $this->repository->bucketAllocationsByDayClinicalFeaturesInRange($from, $toExclusive),
        );
        self::assertSame(
            $this->query->bucketByDayClinicalFeaturesInRangeForHospitals($from, $toExclusive, $hospitalIds),
            $this->repository->bucketAllocationsByDayClinicalFeaturesInRangeForHospitals($from, $toExclusive, $hospitalIds),
        );
    }

    /**
     * @param array<string, array<string, int>> $buckets
     */
    private function assertNestedBucketCount(array $buckets, string $outerKey, string $innerKey, int $expected): void
    {
        self::assertArrayHasKey($outerKey, $buckets);
        self::assertArrayHasKey($innerKey, $buckets[$outerKey]);
        self::assertSame($expected, $buckets[$outerKey][$innerKey]);
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: object}
     */
    private function march2024FixtureContext(): array
    {
        return [
            new \DateTimeImmutable('2024-03-01 00:00:00'),
            new \DateTimeImmutable('2024-04-01 00:00:00'),
            $this->seedAllocationGraph(),
        ];
    }

    private function seedAllocationGraph(): object
    {
        UserFactory::createOne();
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createOne();
        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        OccasionFactory::createOne();
        InfectionFactory::createOne();
        IndicationRawFactory::createOne();
        IndicationNormalizedFactory::createOne();

        return ImportFactory::createOne();
    }
}
