<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\SummarizedReport;

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
use App\Statistics\Application\SummarizedReport\Monthly\Dto\MonthlyReportView;
use App\Statistics\Application\SummarizedReport\Monthly\MonthlyReportBuilder;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;

/**
 * Sparkline of the allocation tile (#1239): six calendar months up to the report month, months before the first
 * month with data left out, later empty months as 0.
 *
 * Fixture, one hospital: September 2023: 4, November 2023: 2, January 2024: 3, March 2024: 1 allocation.
 * Report month March 2024: the window is October 2023 to March 2024, so September is outside it.
 */
final class MonthlyReportBuilderTrendTest extends DatabaseKernelTestCase
{
    public function testLeadingEmptyMonthsAreLeftOutAndLaterEmptyMonthsCountAsZero(): void
    {
        $hospitalId = $this->seed(['2023-09-15' => 4, '2023-11-15' => 2, '2024-01-15' => 3, '2024-03-15' => 1]);

        $view = $this->build($hospitalId, 2024, 3);

        // October is empty and comes before the first month with data in the window, so it is left out.
        self::assertSame(
            [
                ['label' => 'Nov 2023', 'value' => 2],
                ['label' => 'Dec 2023', 'value' => 0],
                ['label' => 'Jan 2024', 'value' => 3],
                ['label' => 'Feb 2024', 'value' => 0],
                ['label' => 'Mar 2024', 'value' => 1],
            ],
            $view->allocationTrend,
        );
        self::assertSame($view->allocationCount, $view->allocationTrend[4]['value'], 'the last point is the tile value');
    }

    public function testASingleMonthWithDataShowsNoTrend(): void
    {
        $hospitalId = $this->seed(['2024-03-15' => 2]);

        self::assertSame([], $this->build($hospitalId, 2024, 3)->allocationTrend);
    }

    public function testAReportMonthWithoutDataShowsNoTrend(): void
    {
        $hospitalId = $this->seed(['2024-02-15' => 2, '2024-03-15' => 2]);

        self::assertSame([], $this->build($hospitalId, 2024, 4)->allocationTrend);
    }

    private function build(int $hospitalId, int $year, int $month): MonthlyReportView
    {
        return self::getContainer()->get(MonthlyReportBuilder::class)->build(
            new StatisticsContext(
                null,
                new StatisticsFilter(StatisticsFilterScope::Hospital, $hospitalId, null, StatisticsFilterPeriod::Month, $year, $month),
            ),
            'en',
            new \DateTimeImmutable('2024-08-15 12:00:00', new \DateTimeZone('Europe/Berlin')),
            $year,
            $month,
        );
    }

    /**
     * @param array<string, int> $countsByDay day => allocations on it
     */
    private function seed(array $countsByDay): int
    {
        $user = UserFactory::createOne([
            'email' => sprintf('monthly-trend-%s@example.test', bin2hex(random_bytes(4))),
            'isVerified' => true,
        ]);
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'owner' => $user,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'name' => 'Monthly Trend Hospital',
        ]);
        SpecialityFactory::createOne();
        AssignmentFactory::createOne();
        DepartmentFactory::createOne();
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);

        foreach ($countsByDay as $day => $count) {
            AllocationFactory::createMany($count, [
                'import' => $import,
                'hospital' => $hospital,
                'state' => $state,
                'dispatchArea' => $dispatchArea,
                'indicationRaw' => IndicationRawFactory::createOne(),
                'indicationNormalized' => IndicationNormalizedFactory::createOne(),
                'createdAt' => new \DateTimeImmutable($day.' 10:00:00'),
            ]);
        }

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport((int) $import->getId());

        return (int) $hospital->getId();
    }
}
