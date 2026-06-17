<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Integration\Application;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Engagement\Application\MonthlyReminderComparisonFilterFactory;
use App\Engagement\Application\MonthlyReminderSelfBenchmarkFactory;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkReport;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;

final class MonthlyReminderSelfBenchmarkFactoryTest extends DatabaseKernelTestCase
{
    public function testComparisonFilterFactoryBuildsMonthAndRollingBaselineForSameHospital(): void
    {
        self::bootKernel();

        $filterFactory = self::getContainer()->get(MonthlyReminderComparisonFilterFactory::class);

        $monthFilter = $filterFactory->createPrimaryFilter(
            40,
            StatisticsFilterPeriod::Month,
            2026,
            5,
        );
        $baselineFilter = $filterFactory->createPrimaryFilter(
            40,
            StatisticsFilterPeriod::All,
            null,
            null,
        );

        self::assertSame(StatisticsFilterScope::Hospital, $monthFilter->scope);
        self::assertSame(40, $monthFilter->hospitalId);
        self::assertSame(StatisticsFilterPeriod::Month, $monthFilter->period);
        self::assertSame(2026, $monthFilter->referenceYear);
        self::assertSame(5, $monthFilter->referenceMonth);

        self::assertSame(StatisticsFilterScope::Hospital, $baselineFilter->scope);
        self::assertSame(40, $baselineFilter->hospitalId);
        self::assertSame(StatisticsFilterPeriod::All, $baselineFilter->period);
        self::assertNull($baselineFilter->referenceYear);
        self::assertNull($baselineFilter->referenceMonth);
    }

    public function testBuildReturnsBenchmarkReportForHospital(): void
    {
        self::bootKernel();

        $owner = UserFactory::createOne([
            'email' => sprintf('self-benchmark-%s@example.test', bin2hex(random_bytes(4))),
            'isVerified' => true,
        ]);
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'owner' => $owner,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);

        $factory = self::getContainer()->get(MonthlyReminderSelfBenchmarkFactory::class);

        $report = $factory->build($hospital->getId(), 2026, 5);

        self::assertInstanceOf(BenchmarkReport::class, $report);
    }
}
