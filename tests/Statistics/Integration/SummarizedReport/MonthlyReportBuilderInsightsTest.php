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
use App\Statistics\Application\SummarizedReport\Monthly\MonthlyReportBuilder;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;

final class MonthlyReportBuilderInsightsTest extends DatabaseKernelTestCase
{
    public function testInsightsCompareSelectedMonthAgainstPreviousMonth(): void
    {
        $user = UserFactory::createOne([
            'email' => sprintf('monthly-insights-%s@example.test', bin2hex(random_bytes(4))),
            'isVerified' => true,
        ]);
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'owner' => $user,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'name' => 'Monthly Insights Hospital',
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

        AllocationFactory::createMany(40, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationRaw' => $raw,
            'indicationNormalized' => $normalized,
            'isWithPhysician' => false,
            'createdAt' => new \DateTimeImmutable('2024-02-10 10:00:00'),
        ]);
        AllocationFactory::createMany(40, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationRaw' => $raw,
            'indicationNormalized' => $normalized,
            'isWithPhysician' => true,
            'createdAt' => new \DateTimeImmutable('2024-03-10 10:00:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)
            ->rebuildForImport((int) $import->getId());

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $hospitalId = (int) $hospital->getId();
        $this->insertHospitalKpi($connection, $hospitalId, '2024-02-01', 100, 15);
        $this->insertHospitalKpi($connection, $hospitalId, '2024-03-01', 100, 3);

        /** @var MonthlyReportBuilder $builder */
        $builder = self::getContainer()->get(MonthlyReportBuilder::class);
        $view = $builder->build(
            new StatisticsContext(
                null,
                new StatisticsFilter(
                    StatisticsFilterScope::Hospital,
                    $hospitalId,
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
        self::assertSame(40, $view->allocationCount);
        self::assertSame(0.0, $view->allocationMomPercent);
        self::assertSame(100.0, $view->withPhysicianPercent);
        self::assertSame(100.0, $view->withPhysicianMomPercent);
        self::assertNotEmpty($view->genderSegments);
        foreach ($view->genderSegments as $segment) {
            self::assertNotNull($segment->percentDeltaPp);
        }
        self::assertNotEmpty($view->urgencySegments);
        foreach ($view->urgencySegments as $segment) {
            self::assertNotNull($segment->percentDeltaPp);
        }
        self::assertStringContainsString('comparison_period=month', $view->benchmarkingUrl);
        self::assertStringContainsString('comparison_month=2', $view->benchmarkingUrl);
        self::assertNotEmpty($view->insights);
        $bodies = array_map(static fn (\App\Statistics\Application\Insights\HospitalInsight $insight): string => $insight->title.' '.$insight->body, $view->insights);
        $joined = implode("\n", $bodies);
        self::assertTrue(
            str_contains($joined, 'Arztbegleitung') || str_contains($joined, 'physician') || str_contains($joined, 'Importqualität'),
            'Expected physician or quality insight vs previous month, got: '.$joined,
        );
        self::assertStringContainsString('Februar', $view->previousMonthLabel);
    }

    public function testBuildComputesKpiAndTransportMomDeltasAgainstPreviousMonth(): void
    {
        $user = UserFactory::createOne([
            'email' => sprintf('monthly-kpi-%s@example.test', bin2hex(random_bytes(4))),
            'isVerified' => true,
        ]);
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'owner' => $user,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'name' => 'Monthly KPI Hospital',
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

        AllocationFactory::createMany(20, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationRaw' => $raw,
            'indicationNormalized' => $normalized,
            'isWithPhysician' => false,
            'requiresResus' => false,
            'createdAt' => new \DateTimeImmutable('2024-02-10 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2024-02-10 10:40:00'),
        ]);
        AllocationFactory::createMany(20, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationRaw' => $raw,
            'indicationNormalized' => $normalized,
            'isWithPhysician' => true,
            'requiresResus' => true,
            'createdAt' => new \DateTimeImmutable('2024-03-10 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2024-03-10 10:20:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)
            ->rebuildForImport((int) $import->getId());

        /** @var MonthlyReportBuilder $builder */
        $builder = self::getContainer()->get(MonthlyReportBuilder::class);
        $view = $builder->build(
            new StatisticsContext(
                null,
                new StatisticsFilter(
                    StatisticsFilterScope::Hospital,
                    (int) $hospital->getId(),
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
        self::assertSame(100.0, $view->withPhysicianPercent);
        self::assertSame(100.0, $view->withPhysicianMomPercent);
        self::assertSame(100.0, $view->resusPercent);
        self::assertSame(100.0, $view->resusMomPercent);
        self::assertNotNull($view->medianTransportMinutes);
        self::assertNotNull($view->medianTransportMomMinutes);
        self::assertLessThan(0.0, $view->medianTransportMomMinutes);
    }

    private function insertHospitalKpi(
        Connection $connection,
        int $hospitalId,
        string $date,
        int $recordsTotal,
        int $recordsRejected,
    ): void {
        $connection->insert('kpi_daily', [
            'date' => $date,
            'hospital_id' => $hospitalId,
            'imports_count' => 1,
            'successful_imports_count' => 1,
            'records_total' => $recordsTotal,
            'records_processed' => $recordsTotal,
            'records_rejected' => $recordsRejected,
            'failed_imports_count' => 0,
            'created_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
        ]);
    }
}
