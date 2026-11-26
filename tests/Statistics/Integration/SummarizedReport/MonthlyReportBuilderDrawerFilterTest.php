<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\SummarizedReport;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsDrawerFilter;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\Mapping\AllocationStatsUrgencyProjectionCode;
use App\Statistics\Application\SummarizedReport\Monthly\Dto\MonthlyReportSegment;
use App\Statistics\Application\SummarizedReport\Monthly\Dto\MonthlyReportTopRow;
use App\Statistics\Application\SummarizedReport\Monthly\Dto\MonthlyReportView;
use App\Statistics\Application\SummarizedReport\Monthly\MonthlyReportBuilder;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;

/**
 * Every number of the monthly report follows the filter drawer (#1039).
 *
 * Fixture, one hospital:
 * - March 2024: 3 emergency (male, with physician, closed department A), 1 emergency (female, department B),
 *   6 inpatient (female, department B).
 * - February 2024: 2 emergency (one with physician), 3 inpatient.
 *
 * Drawer "urgency = emergency": March 4 allocations, February 2.
 */
final class MonthlyReportBuilderDrawerFilterTest extends DatabaseKernelTestCase
{
    public function testEveryNumberFollowsTheDrawerFilter(): void
    {
        $hospitalId = $this->seed();

        $view = $this->build($hospitalId, new StatisticsDrawerFilter(urgency: AllocationStatsUrgencyProjectionCode::Emergency->value));

        self::assertSame(4, $view->allocationCount);
        self::assertSame(75.0, $view->withPhysicianPercent, '3 of 4');
        self::assertSame(25.0, $view->withPhysicianMomPercent, '75 % against 1 of 2 = 50 % in February');
        self::assertSame([4, 0, 0], $this->segmentCounts($view->urgencySegments));
        self::assertSame([100.0, 0.0, 0.0], $this->segmentPercents($view->urgencySegments));
        self::assertSame([3, 1, 0], $this->segmentCounts($view->genderSegments));
        self::assertSame([75.0, 25.0, 0.0], $this->segmentPercents($view->genderSegments));
        self::assertSame(
            [['Department A', 3, '75.0%'], ['Department B', 1, '25.0%']],
            $this->topRows($view->topDepartments),
            'shares of the top list use the filtered count as denominator',
        );
        self::assertSame(3, $view->closedDepartment->closedCount);
        self::assertSame(75.0, $view->closedDepartment->sharePercent);
        self::assertSame(4, array_sum($view->dailyChart['counts']));
        // Sparkline (#1239): data starts in February, so October to January are left out; the last point is the tile.
        self::assertSame([['label' => 'Feb 2024', 'value' => 2], ['label' => 'Mar 2024', 'value' => 4]], $view->allocationTrend);
    }

    public function testWithoutDrawerFilterTheReportCountsEveryAllocation(): void
    {
        $hospitalId = $this->seed();

        $view = $this->build($hospitalId, null);

        self::assertSame(10, $view->allocationCount);
        self::assertSame(30.0, $view->withPhysicianPercent, '3 of 10');
        self::assertSame([4, 6, 0], $this->segmentCounts($view->urgencySegments));
        self::assertSame([3, 7, 0], $this->segmentCounts($view->genderSegments));
        self::assertSame(
            [['Department B', 7, '70.0%'], ['Department A', 3, '30.0%']],
            $this->topRows($view->topDepartments),
        );
        self::assertSame(3, $view->closedDepartment->closedCount);
        self::assertSame(30.0, $view->closedDepartment->sharePercent);
        self::assertSame(10, array_sum($view->dailyChart['counts']));
        self::assertSame([['label' => 'Feb 2024', 'value' => 5], ['label' => 'Mar 2024', 'value' => 10]], $view->allocationTrend);
    }

    private function build(int $hospitalId, ?StatisticsDrawerFilter $drawerFilter): MonthlyReportView
    {
        return self::getContainer()->get(MonthlyReportBuilder::class)->build(
            new StatisticsContext(
                null,
                new StatisticsFilter(StatisticsFilterScope::Hospital, $hospitalId, null, StatisticsFilterPeriod::Month, 2024, 3),
                drawerFilter: $drawerFilter,
            ),
            'en',
            new \DateTimeImmutable('2024-08-15 12:00:00', new \DateTimeZone('Europe/Berlin')),
            2024,
            3,
        );
    }

    /**
     * @param list<MonthlyReportSegment> $segments
     *
     * @return list<int>
     */
    private function segmentCounts(array $segments): array
    {
        return array_map(static fn (MonthlyReportSegment $segment): int => $segment->count, $segments);
    }

    /**
     * @param list<MonthlyReportSegment> $segments
     *
     * @return list<float>
     */
    private function segmentPercents(array $segments): array
    {
        return array_map(static fn (MonthlyReportSegment $segment): float => $segment->percent, $segments);
    }

    /**
     * @param list<MonthlyReportTopRow> $rows
     *
     * @return list<array{0: string, 1: int, 2: string}>
     */
    private function topRows(array $rows): array
    {
        return array_map(static fn (MonthlyReportTopRow $row): array => [$row->label, $row->count, $row->shareDisplay], $rows);
    }

    private function seed(): int
    {
        $user = UserFactory::createOne([
            'email' => sprintf('monthly-drawer-%s@example.test', bin2hex(random_bytes(4))),
            'isVerified' => true,
        ]);
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'owner' => $user,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'name' => 'Monthly Drawer Filter Hospital',
        ]);
        SpecialityFactory::createOne();
        AssignmentFactory::createOne();
        $departmentA = DepartmentFactory::createOne(['name' => 'Department A']);
        $departmentB = DepartmentFactory::createOne(['name' => 'Department B']);
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);
        $defaults = [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationRaw' => IndicationRawFactory::createOne(),
            'indicationNormalized' => IndicationNormalizedFactory::createOne(),
            'requiresResus' => false,
        ];

        $this->allocations(3, $defaults, '2024-03-10 10:00:00', AllocationUrgency::EMERGENCY, AllocationGender::MALE, true, $departmentA, true);
        $this->allocations(1, $defaults, '2024-03-11 10:00:00', AllocationUrgency::EMERGENCY, AllocationGender::FEMALE, false, $departmentB, false);
        $this->allocations(6, $defaults, '2024-03-12 10:00:00', AllocationUrgency::INPATIENT, AllocationGender::FEMALE, false, $departmentB, false);
        $this->allocations(1, $defaults, '2024-02-10 10:00:00', AllocationUrgency::EMERGENCY, AllocationGender::MALE, true, $departmentB, false);
        $this->allocations(1, $defaults, '2024-02-10 11:00:00', AllocationUrgency::EMERGENCY, AllocationGender::MALE, false, $departmentB, false);
        $this->allocations(3, $defaults, '2024-02-11 10:00:00', AllocationUrgency::INPATIENT, AllocationGender::FEMALE, false, $departmentB, false);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport((int) $import->getId());

        return (int) $hospital->getId();
    }

    /**
     * @param array<string, mixed> $defaults
     */
    private function allocations(
        int $count,
        array $defaults,
        string $createdAt,
        AllocationUrgency $urgency,
        AllocationGender $gender,
        bool $withPhysician,
        object $department,
        bool $departmentWasClosed,
    ): void {
        AllocationFactory::createMany($count, [
            ...$defaults,
            'createdAt' => new \DateTimeImmutable($createdAt),
            'urgency' => $urgency,
            'gender' => $gender,
            'isWithPhysician' => $withPhysician,
            'department' => $department,
            'departmentWasClosed' => $departmentWasClosed,
        ]);
    }
}
