<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Query;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;

final class AllocationBucketQueryHospitalScopeTest extends AllocationBucketQueryTestCase
{
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
}
