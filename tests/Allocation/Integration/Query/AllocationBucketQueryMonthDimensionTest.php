<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Query;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;

final class AllocationBucketQueryMonthDimensionTest extends AllocationBucketQueryTestCase
{
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
}
