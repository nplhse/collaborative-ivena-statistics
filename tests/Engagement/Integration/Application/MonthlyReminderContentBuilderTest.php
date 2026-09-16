<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Integration\Application;

use App\Allocation\Domain\Enum\AllocationUrgency;
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
use App\Engagement\Application\MonthlyReminderContentBuilder;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;

final class MonthlyReminderContentBuilderTest extends DatabaseKernelTestCase
{
    private MonthlyReminderContentBuilder $builder;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->builder = $container->get(MonthlyReminderContentBuilder::class);
        $this->connection = $container->get(Connection::class);
    }

    public function testBuildUsesFallbackContentWhenHospitalHasLittleData(): void
    {
        $referenceDate = new \DateTimeImmutable('2026-07-01', new \DateTimeZone('Europe/Berlin'));
        $hospital = $this->seedHospital();

        $content = $this->builder->build($hospital, $referenceDate, 'en');

        self::assertFalse($content->isPersonalized);
        self::assertSame(0, $content->allocationCount);
        self::assertCount(2, $content->insights);
        self::assertNotNull($content->platformAllocationCount);
        self::assertNotNull($content->longestSubmissionGapLabel);
        self::assertNotSame('', $content->lastImportLabel);
        self::assertTrue($content->lastImportStale);
    }

    public function testBuildPersonalizedContentWhenReportingMonthHasEnoughAllocations(): void
    {
        $referenceDate = new \DateTimeImmutable('2026-07-01', new \DateTimeZone('Europe/Berlin'));
        $seed = $this->seedHospitalGraph();
        $hospital = $seed['hospital'];
        $import = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $seed['user'],
            'createdAt' => new \DateTimeImmutable('2026-05-10'),
            'status' => ImportStatus::COMPLETED,
        ]);

        AllocationFactory::createMany(20, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $seed['state'],
            'dispatchArea' => $seed['dispatchArea'],
            'indicationRaw' => $seed['raw'],
            'indicationNormalized' => $seed['normalized'],
            'departmentWasClosed' => false,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2026-05-15'),
        ]);
        AllocationFactory::createMany(20, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $seed['state'],
            'dispatchArea' => $seed['dispatchArea'],
            'indicationRaw' => $seed['raw'],
            'indicationNormalized' => $seed['normalized'],
            'departmentWasClosed' => false,
            'urgency' => AllocationUrgency::INPATIENT,
            'createdAt' => new \DateTimeImmutable('2026-04-15'),
        ]);

        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->rebuildForImport((int) $import->getId());

        $this->insertHospitalKpi(
            (int) $hospital->getId(),
            '2026-03-01',
            100,
            15,
        );
        $this->insertHospitalKpi(
            (int) $hospital->getId(),
            '2026-04-01',
            100,
            5,
        );

        $content = $this->builder->build($hospital, $referenceDate, 'en');

        self::assertTrue($content->isPersonalized);
        self::assertGreaterThanOrEqual(20, $content->allocationCount);
        self::assertNotSame('', $content->preheader);
        self::assertGreaterThan(0, $content->submissionMonthsTotal);
        self::assertNotEmpty($content->chartBars);
        self::assertNull($content->platformAllocationCount);
        self::assertStringContainsString('/statistics/reports/monthly', $content->monthlyReportUrl);
        self::assertStringNotContainsString('type=monthly', $content->monthlyReportUrl);
        self::assertStringContainsString('year=2026', $content->monthlyReportUrl);
        self::assertStringContainsString('month=5', $content->monthlyReportUrl);
        self::assertSame(0, $content->closedDepartmentCount);
        self::assertSame(0.0, $content->closedDepartmentSharePercent);
        self::assertNotNull($content->urgencyBenchmarkNote);
        self::assertStringNotContainsString('label.urgency.', $content->urgencyBenchmarkNote);
        self::assertStringContainsString('Emergency Care', $content->urgencyBenchmarkNote);
    }

    public function testBuildIncludesClosedDepartmentMetricsForReportingMonth(): void
    {
        $referenceDate = new \DateTimeImmutable('2026-07-01', new \DateTimeZone('Europe/Berlin'));
        $seed = $this->seedHospitalGraph();
        $hospital = $seed['hospital'];
        $import = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $seed['user'],
            'createdAt' => new \DateTimeImmutable('2026-05-10'),
            'status' => ImportStatus::COMPLETED,
        ]);

        AllocationFactory::createMany(4, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $seed['state'],
            'dispatchArea' => $seed['dispatchArea'],
            'indicationRaw' => $seed['raw'],
            'indicationNormalized' => $seed['normalized'],
            'departmentWasClosed' => true,
            'createdAt' => new \DateTimeImmutable('2026-05-15'),
        ]);
        AllocationFactory::createMany(16, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $seed['state'],
            'dispatchArea' => $seed['dispatchArea'],
            'indicationRaw' => $seed['raw'],
            'indicationNormalized' => $seed['normalized'],
            'departmentWasClosed' => false,
            'createdAt' => new \DateTimeImmutable('2026-05-16'),
        ]);
        AllocationFactory::createMany(2, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $seed['state'],
            'dispatchArea' => $seed['dispatchArea'],
            'indicationRaw' => $seed['raw'],
            'indicationNormalized' => $seed['normalized'],
            'departmentWasClosed' => true,
            'createdAt' => new \DateTimeImmutable('2026-04-15'),
        ]);

        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->rebuildForImport((int) $import->getId());

        $content = $this->builder->build($hospital, $referenceDate, 'en');

        self::assertTrue($content->isPersonalized);
        self::assertSame(4, $content->closedDepartmentCount);
        self::assertSame(20.0, $content->closedDepartmentSharePercent);
        self::assertSame(100.0, $content->closedDepartmentMomPercent);
        self::assertStringContainsString('/statistics/reports/monthly', $content->monthlyReportUrl);
        self::assertStringContainsString('year=2026', $content->monthlyReportUrl);
        self::assertStringContainsString('month=5', $content->monthlyReportUrl);
    }

    public function testBuildUsesGermanPeriodLabelsForGermanLocale(): void
    {
        $referenceDate = new \DateTimeImmutable('2026-07-01', new \DateTimeZone('Europe/Berlin'));
        $hospital = $this->seedHospital();

        $content = $this->builder->build($hospital, $referenceDate, 'de');

        self::assertStringContainsString('Mai 2026', $content->reportingPeriodLabel);
        self::assertStringContainsString('Juni 2026', $content->uploadMonthLabel);
        self::assertStringNotContainsString('May 2026', $content->reportingPeriodLabel);
    }

    private function seedHospital(): mixed
    {
        $owner = UserFactory::createOne([
            'email' => sprintf('content-builder-%s@example.test', bin2hex(random_bytes(4))),
            'isVerified' => true,
        ]);
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);

        return HospitalFactory::createOne([
            'owner' => $owner,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'name' => 'Reminder Content Hospital',
        ]);
    }

    /**
     * @return array{
     *     hospital: object,
     *     user: object,
     *     state: object,
     *     dispatchArea: object,
     *     raw: object,
     *     normalized: object,
     * }
     */
    private function seedHospitalGraph(): array
    {
        $user = UserFactory::createOne([
            'email' => sprintf('content-graph-%s@example.test', bin2hex(random_bytes(4))),
            'isVerified' => true,
        ]);
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'owner' => $user,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'name' => 'Personalized Reminder Hospital',
        ]);

        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        OccasionFactory::createOne();
        InfectionFactory::createOne();
        $raw = IndicationRawFactory::createOne();
        $normalized = IndicationNormalizedFactory::createOne();

        return [
            'hospital' => $hospital,
            'user' => $user,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'raw' => $raw,
            'normalized' => $normalized,
        ];
    }

    private function insertHospitalKpi(int $hospitalId, string $date, int $recordsTotal, int $recordsRejected): void
    {
        $this->connection->insert('kpi_daily', [
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
