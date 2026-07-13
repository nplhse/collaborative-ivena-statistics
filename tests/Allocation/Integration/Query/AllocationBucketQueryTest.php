<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Query;

use App\Allocation\Domain\Enum\AllocationGender;
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

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(AllocationBucketQuery::class);
    }

    public function testBucketByMonthAndGenderInRangeGroupsByMonthAndGender(): void
    {
        $from = new \DateTimeImmutable('2024-03-01 00:00:00');
        $toExclusive = new \DateTimeImmutable('2024-04-01 00:00:00');
        $import = $this->seedAllocationGraph();

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
