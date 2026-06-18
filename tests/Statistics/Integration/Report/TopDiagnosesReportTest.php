<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Report;

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
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\Application\Report\TopDiagnosesReport;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class TopDiagnosesReportTest extends KernelTestCase
{
    use Factories;

    public function testBuildsTableWidgetWithIndicationNavigationTargets(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'top-diagnoses-report-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'TopDiagnosesReportState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'TopDiagnosesReportDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'TopDiagnosesReportHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);

        SpecialityFactory::createOne(['name' => 'TopDiagnosesReportSpec']);
        DepartmentFactory::createOne(['name' => 'TopDiagnosesReportDept']);
        AssignmentFactory::createOne(['name' => 'TopDiagnosesReportAssign']);
        IndicationRawFactory::createOne(['name' => 'TopDiagnosesReportRaw', 'code' => 912_353]);
        $indication = IndicationNormalizedFactory::createOne(['name' => 'Report STEMI']);

        $import = ImportFactory::createOne(['name' => 'TopDiagnosesReportImport', 'hospital' => $hospital, 'createdBy' => $user]);
        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationNormalized' => $indication,
            'createdAt' => new \DateTimeImmutable('2026-04-01 12:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 12:20:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $report = self::getContainer()->get(TopDiagnosesReport::class);
        $context = new StatisticsContext(
            $user,
            new StatisticsFilter(StatisticsFilterScope::Hospital, $hospital->getId(), null, StatisticsFilterPeriod::All),
        );

        $widget = $report->build($context, 25);

        self::assertSame(StatisticWidgetType::Table, $widget->type);
        self::assertSame('top_diagnoses_table', $widget->id);
        self::assertSame('Report STEMI', $widget->payload['rows'][0][1]);
        self::assertNotNull($widget->payload['diagnosisRowTargets'][0]);
        self::assertSame('app_stats_indication_dashboard', $widget->payload['diagnosisRowTargets'][0]->route);
        self::assertSame($indication->getId(), $widget->payload['diagnosisRowTargets'][0]->params['indicationId']);
    }
}
