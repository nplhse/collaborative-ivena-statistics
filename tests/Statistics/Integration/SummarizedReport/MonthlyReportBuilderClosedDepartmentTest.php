<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\SummarizedReport;

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
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\SummarizedReport\Monthly\MonthlyReportBuilder;
use App\Statistics\ClosedDepartmentAssignments\Infrastructure\Query\ClosedDepartmentMetricsQuery;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;

final class MonthlyReportBuilderClosedDepartmentTest extends DatabaseKernelTestCase
{
    public function testClosedDepartmentSectionReusesMetricsQueryAndPreviousMonth(): void
    {
        $seed = $this->seedHospitalGraph();
        $departmentA = DepartmentFactory::createOne(['name' => 'Closed Dept A']);
        $departmentB = DepartmentFactory::createOne(['name' => 'Open Dept B']);

        AllocationFactory::createMany(2, [
            ...$seed['allocationDefaults'],
            'department' => $departmentA,
            'departmentWasClosed' => true,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2024-02-10 10:00:00'),
        ]);
        AllocationFactory::createMany(8, [
            ...$seed['allocationDefaults'],
            'department' => $departmentB,
            'departmentWasClosed' => false,
            'urgency' => AllocationUrgency::INPATIENT,
            'createdAt' => new \DateTimeImmutable('2024-02-11 10:00:00'),
        ]);
        AllocationFactory::createMany(3, [
            ...$seed['allocationDefaults'],
            'department' => $departmentA,
            'departmentWasClosed' => true,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2024-03-10 10:00:00'),
        ]);
        AllocationFactory::createOne([
            ...$seed['allocationDefaults'],
            'department' => $departmentA,
            'departmentWasClosed' => true,
            'urgency' => AllocationUrgency::INPATIENT,
            'createdAt' => new \DateTimeImmutable('2024-03-10 11:00:00'),
        ]);
        AllocationFactory::createMany(6, [
            ...$seed['allocationDefaults'],
            'department' => $departmentB,
            'departmentWasClosed' => false,
            'urgency' => AllocationUrgency::INPATIENT,
            'createdAt' => new \DateTimeImmutable('2024-03-12 10:00:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)
            ->rebuildForImport((int) $seed['import']->getId());

        $filter = new StatisticsFilter(
            StatisticsFilterScope::Hospital,
            $seed['hospitalId'],
            null,
            StatisticsFilterPeriod::Month,
            2024,
            3,
        );
        $view = self::getContainer()->get(MonthlyReportBuilder::class)->build(
            new StatisticsContext(null, $filter),
            'de',
            new \DateTimeImmutable('2024-08-15 12:00:00', new \DateTimeZone('Europe/Berlin')),
            2024,
            3,
        );

        $bounds = StatisticsPeriodResolver::resolve($filter);
        $queryRow = self::getContainer()->get(ClosedDepartmentMetricsQuery::class)->fetch(
            $bounds->from,
            $bounds->toExclusive,
            new StatisticsScopeCriteria([$seed['hospitalId']]),
        );

        self::assertTrue($view->hasData);
        self::assertSame(10, $view->allocationCount);
        self::assertSame($queryRow->closedCount, $view->closedDepartment->closedCount);
        self::assertSame(4, $view->closedDepartment->closedCount);
        self::assertSame(40.0, $view->closedDepartment->sharePercent);
        self::assertSame(100.0, $view->closedDepartment->closedMomPercent);
        self::assertSame(1, $view->closedDepartment->departmentCount);
        self::assertSame(2, $view->closedDepartment->totalDepartmentCount);
        self::assertCount(3, $view->closedDepartment->urgencySegments);
        self::assertSame(3, $view->closedDepartment->urgencySegments[0]->count);
        self::assertSame(1, $view->closedDepartment->urgencySegments[1]->count);
        self::assertSame(0, $view->closedDepartment->urgencySegments[2]->count);
        self::assertStringContainsString('/statistics/closed-department-assignments', $view->closedDepartment->detailUrl);
        self::assertStringContainsString('period=month', $view->closedDepartment->detailUrl);
        self::assertStringContainsString('year=2024', $view->closedDepartment->detailUrl);
        self::assertStringContainsString('month=3', $view->closedDepartment->detailUrl);
        self::assertStringContainsString((string) $seed['hospitalId'], $view->closedDepartment->detailUrl);
    }

    public function testClosedDepartmentSectionShowsZeroStateWhenMonthHasNoClosedAllocations(): void
    {
        $seed = $this->seedHospitalGraph();
        AllocationFactory::createMany(5, [
            ...$seed['allocationDefaults'],
            'departmentWasClosed' => false,
            'createdAt' => new \DateTimeImmutable('2024-03-10 10:00:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)
            ->rebuildForImport((int) $seed['import']->getId());

        $view = self::getContainer()->get(MonthlyReportBuilder::class)->build(
            new StatisticsContext(
                null,
                new StatisticsFilter(
                    StatisticsFilterScope::Hospital,
                    $seed['hospitalId'],
                    null,
                    StatisticsFilterPeriod::Month,
                    2024,
                    3,
                ),
            ),
            'de',
            new \DateTimeImmutable('2024-08-15 12:00:00', new \DateTimeZone('Europe/Berlin')),
            2024,
            3,
        );

        self::assertTrue($view->hasData);
        self::assertSame(0, $view->closedDepartment->closedCount);
        self::assertSame(0.0, $view->closedDepartment->sharePercent);
        self::assertSame([], $view->closedDepartment->urgencySegments);
        self::assertSame(0, $view->closedDepartment->departmentCount);
        self::assertGreaterThan(0, $view->closedDepartment->totalDepartmentCount);
    }

    /**
     * @return array{
     *     hospitalId: int,
     *     import: object,
     *     allocationDefaults: array<string, mixed>
     * }
     */
    private function seedHospitalGraph(): array
    {
        $user = UserFactory::createOne([
            'email' => sprintf('monthly-cda-%s@example.test', bin2hex(random_bytes(4))),
            'isVerified' => true,
        ]);
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'owner' => $user,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'name' => 'Monthly Closed Department Hospital',
        ]);
        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        $raw = IndicationRawFactory::createOne();
        $normalized = IndicationNormalizedFactory::createOne();
        $import = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
        ]);

        return [
            'hospitalId' => (int) $hospital->getId(),
            'import' => $import,
            'allocationDefaults' => [
                'import' => $import,
                'hospital' => $hospital,
                'state' => $state,
                'dispatchArea' => $dispatchArea,
                'indicationRaw' => $raw,
                'indicationNormalized' => $normalized,
            ],
        ];
    }
}
