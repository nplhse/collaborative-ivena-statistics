<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Report;

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
use App\Statistics\Application\Mapping\StatisticsAgeGroupFilter;
use App\Statistics\Application\Report\TopDiagnosesReport;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class TopDiagnosesReportDrawerFilterTest extends KernelTestCase
{
    use Factories;

    public function testUrgencyDrawerFilterNarrowsRowsAndShareTotal(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'top-diagnoses-filter-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'TopDiagnosesFilterState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'TopDiagnosesFilterDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'TopDiagnosesFilterHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);

        SpecialityFactory::createOne(['name' => 'TopDiagnosesFilterSpec']);
        DepartmentFactory::createOne(['name' => 'TopDiagnosesFilterDept']);
        AssignmentFactory::createOne(['name' => 'TopDiagnosesFilterAssign']);
        IndicationRawFactory::createOne(['name' => 'TopDiagnosesFilterRaw', 'code' => 912_354]);
        $emergencyIndication = IndicationNormalizedFactory::createOne(['name' => 'Filter STEMI Emergency']);
        $inpatientIndication = IndicationNormalizedFactory::createOne(['name' => 'Filter STEMI Inpatient']);

        $import = ImportFactory::createOne(['name' => 'TopDiagnosesFilterImport', 'hospital' => $hospital, 'createdBy' => $user]);
        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationNormalized' => $emergencyIndication,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2026-04-01 12:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 12:20:00'),
        ]);
        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationNormalized' => $inpatientIndication,
            'urgency' => AllocationUrgency::INPATIENT,
            'createdAt' => new \DateTimeImmutable('2026-04-01 13:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 13:20:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $report = self::getContainer()->get(TopDiagnosesReport::class);
        $context = new StatisticsContext(
            $user,
            new StatisticsFilter(StatisticsFilterScope::Hospital, $hospital->getId(), null, StatisticsFilterPeriod::All),
            drawerFilter: new StatisticsDrawerFilter(urgency: AllocationUrgency::EMERGENCY->value),
        );

        $widget = $report->build($context, 25);

        self::assertCount(1, $widget->payload['rows']);
        self::assertSame('Filter STEMI Emergency', $widget->payload['rows'][0][1]);
        self::assertSame('1', $widget->payload['rows'][0][2]);
        self::assertSame('100.0%', $widget->payload['rows'][0][3]);
    }

    public function testUnder18DrawerFilterNarrowsRows(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'top-diagnoses-age-filter-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'TopDiagnosesAgeFilterState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'TopDiagnosesAgeDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'TopDiagnosesAgeFilterHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);

        SpecialityFactory::createOne(['name' => 'TopDiagnosesAgeFilterSpec']);
        DepartmentFactory::createOne(['name' => 'TopDiagnosesAgeFilterDept']);
        AssignmentFactory::createOne(['name' => 'TopDiagnosesAgeFilterAssign']);
        IndicationRawFactory::createOne(['name' => 'TopDiagnosesAgeFilterRaw', 'code' => 912_355]);
        $youngIndication = IndicationNormalizedFactory::createOne(['name' => 'Filter Young Patient']);
        $adultIndication = IndicationNormalizedFactory::createOne(['name' => 'Filter Adult Patient']);

        $import = ImportFactory::createOne(['name' => 'TopDiagnosesAgeFilterImport', 'hospital' => $hospital, 'createdBy' => $user]);
        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationNormalized' => $youngIndication,
            'age' => 12,
            'createdAt' => new \DateTimeImmutable('2026-04-01 12:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 12:20:00'),
        ]);
        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationNormalized' => $adultIndication,
            'age' => 45,
            'createdAt' => new \DateTimeImmutable('2026-04-01 13:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 13:20:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $report = self::getContainer()->get(TopDiagnosesReport::class);
        $context = new StatisticsContext(
            $user,
            new StatisticsFilter(StatisticsFilterScope::Hospital, $hospital->getId(), null, StatisticsFilterPeriod::All),
            drawerFilter: new StatisticsDrawerFilter(ageGroup: StatisticsAgeGroupFilter::UNDER_18),
        );

        $widget = $report->build($context, 25);

        self::assertCount(1, $widget->payload['rows']);
        self::assertSame('Filter Young Patient', $widget->payload['rows'][0][1]);
    }
}
