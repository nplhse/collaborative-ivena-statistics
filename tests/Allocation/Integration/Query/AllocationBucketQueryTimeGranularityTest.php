<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Query;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Factory\AllocationFactory;

final class AllocationBucketQueryTimeGranularityTest extends AllocationBucketQueryTestCase
{
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
}
