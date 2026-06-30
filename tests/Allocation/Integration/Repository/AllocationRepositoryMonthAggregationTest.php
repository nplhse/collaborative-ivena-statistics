<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Repository;

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
use App\Allocation\Infrastructure\Repository\AllocationRepository;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AllocationRepositoryMonthAggregationTest extends KernelTestCase
{
    use Factories;

    private AllocationRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(AllocationRepository::class);
    }

    public function testCountByMonthLast12MonthsAggregatesInWindowOnly(): void
    {
        $from = new \DateTimeImmutable('first day of this month')
            ->modify('-11 months')
            ->setTime(0, 0, 0);

        $monthA = $from->modify('+10 days');
        $monthB = $from->modify('+1 month +10 days');
        $outOfWindow = $from->modify('-1 day');

        $import = $this->seedAllocationGraph();

        AllocationFactory::createOne(['createdAt' => $monthA, 'import' => $import]);
        AllocationFactory::createOne(['createdAt' => $monthA->modify('+2 days'), 'import' => $import]);
        AllocationFactory::createOne(['createdAt' => $monthB, 'import' => $import]);
        AllocationFactory::createOne(['createdAt' => $outOfWindow, 'import' => $import]);

        $rows = $this->repository->countByMonthLast12Months();

        self::assertSame([
            ['year' => (int) $monthA->format('Y'), 'month' => (int) $monthA->format('n'), 'count' => 2],
            ['year' => (int) $monthB->format('Y'), 'month' => (int) $monthB->format('n'), 'count' => 1],
        ], $rows);
    }

    public function testCountByMonthLast12MonthsForHospitalsFiltersByHospital(): void
    {
        $from = new \DateTimeImmutable('first day of this month')
            ->modify('-11 months')
            ->setTime(0, 0, 0);
        $inWindow = $from->modify('+10 days');

        $import = $this->seedAllocationGraph();
        $hospitalA = HospitalFactory::createOne();
        $hospitalB = HospitalFactory::createOne();

        AllocationFactory::createOne(['createdAt' => $inWindow, 'hospital' => $hospitalA, 'import' => $import]);
        AllocationFactory::createOne(['createdAt' => $inWindow, 'hospital' => $hospitalB, 'import' => $import]);

        $rows = $this->repository->countByMonthLast12MonthsForHospitals([(int) $hospitalA->getId()]);

        self::assertSame([
            ['year' => (int) $inWindow->format('Y'), 'month' => (int) $inWindow->format('n'), 'count' => 1],
        ], $rows);
    }

    public function testCountByMonthLast12MonthsForHospitalsReturnsEmptyForNoIds(): void
    {
        self::assertSame([], $this->repository->countByMonthLast12MonthsForHospitals([]));
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
