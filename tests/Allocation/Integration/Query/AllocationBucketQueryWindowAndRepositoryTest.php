<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Query;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;

final class AllocationBucketQueryWindowAndRepositoryTest extends AllocationBucketQueryTestCase
{
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
}
