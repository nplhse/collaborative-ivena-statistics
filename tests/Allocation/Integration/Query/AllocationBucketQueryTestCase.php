<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Query;

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
abstract class AllocationBucketQueryTestCase extends KernelTestCase
{
    use Factories;

    protected AllocationBucketQuery $query;
    protected AllocationRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(AllocationBucketQuery::class);
        $this->repository = self::getContainer()->get(AllocationRepository::class);
    }

    /**
     * @param array<string, array<string, int>> $buckets
     */
    protected function assertNestedBucketCount(array $buckets, string $outerKey, string $innerKey, int $expected): void
    {
        self::assertArrayHasKey($outerKey, $buckets);
        self::assertArrayHasKey($innerKey, $buckets[$outerKey]);
        self::assertSame($expected, $buckets[$outerKey][$innerKey]);
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: object}
     */
    protected function march2024FixtureContext(): array
    {
        return [
            new \DateTimeImmutable('2024-03-01 00:00:00'),
            new \DateTimeImmutable('2024-04-01 00:00:00'),
            $this->seedAllocationGraph(),
        ];
    }

    protected function seedAllocationGraph(): object
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
