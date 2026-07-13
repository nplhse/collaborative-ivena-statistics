<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Query;

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
use App\Allocation\Infrastructure\Query\AllocationTimeSeriesQuery;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AllocationTimeSeriesQueryTest extends KernelTestCase
{
    use Factories;

    private AllocationTimeSeriesQuery $query;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(AllocationTimeSeriesQuery::class);
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

        $rows = $this->query->countByMonthLast12Months();

        self::assertSame([
            ['year' => (int) $monthA->format('Y'), 'month' => (int) $monthA->format('n'), 'count' => 2],
            ['year' => (int) $monthB->format('Y'), 'month' => (int) $monthB->format('n'), 'count' => 1],
        ], $rows);
    }

    public function testCountAllocationsByMonthInRangeRespectsBounds(): void
    {
        $from = new \DateTimeImmutable('2024-03-01 00:00:00');
        $toExclusive = new \DateTimeImmutable('2024-05-01 00:00:00');
        $import = $this->seedAllocationGraph();

        AllocationFactory::createOne(['createdAt' => new \DateTimeImmutable('2024-03-15'), 'import' => $import]);
        AllocationFactory::createOne(['createdAt' => new \DateTimeImmutable('2024-03-20'), 'import' => $import]);
        AllocationFactory::createOne(['createdAt' => new \DateTimeImmutable('2024-04-10'), 'import' => $import]);
        AllocationFactory::createOne(['createdAt' => new \DateTimeImmutable('2024-05-01'), 'import' => $import]);

        $rows = $this->query->countAllocationsByMonthInRange($from, $toExclusive);

        self::assertSame([
            ['year' => 2024, 'month' => 3, 'count' => 2],
            ['year' => 2024, 'month' => 4, 'count' => 1],
        ], $rows);
    }

    public function testCountAllocationsByDayInRangeBucketsByDay(): void
    {
        $from = new \DateTimeImmutable('2024-06-01 00:00:00');
        $toExclusive = new \DateTimeImmutable('2024-06-04 00:00:00');
        $import = $this->seedAllocationGraph();

        AllocationFactory::createOne(['createdAt' => new \DateTimeImmutable('2024-06-01 10:00:00'), 'import' => $import]);
        AllocationFactory::createOne(['createdAt' => new \DateTimeImmutable('2024-06-01 14:00:00'), 'import' => $import]);
        AllocationFactory::createOne(['createdAt' => new \DateTimeImmutable('2024-06-03 08:00:00'), 'import' => $import]);
        AllocationFactory::createOne(['createdAt' => new \DateTimeImmutable('2024-06-04 08:00:00'), 'import' => $import]);

        $counts = $this->query->countAllocationsByDayInRange($from, $toExclusive);

        self::assertSame(2, $counts['2024-06-01']);
        self::assertSame(1, $counts['2024-06-03']);
        self::assertArrayNotHasKey('2024-06-04', $counts);
    }

    public function testCountAllocationsByCalendarMonthOfYearInRangeAggregatesAcrossYears(): void
    {
        $from = new \DateTimeImmutable('2023-01-01 00:00:00');
        $import = $this->seedAllocationGraph();

        AllocationFactory::createOne(['createdAt' => new \DateTimeImmutable('2023-03-10'), 'import' => $import]);
        AllocationFactory::createOne(['createdAt' => new \DateTimeImmutable('2024-03-20'), 'import' => $import]);
        AllocationFactory::createOne(['createdAt' => new \DateTimeImmutable('2024-07-05'), 'import' => $import]);

        $counts = $this->query->countAllocationsByCalendarMonthOfYearInRange($from, null);

        self::assertSame(2, $counts['cal-03']);
        self::assertSame(1, $counts['cal-07']);
        self::assertSame(0, $counts['cal-01']);
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
