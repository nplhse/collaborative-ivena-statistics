<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\CaseFlow;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\CaseFlow\Infrastructure\Query\CaseFlowDispatchAreaMatch;
use App\Statistics\CaseFlow\Infrastructure\Query\CaseFlowOriginDistributionQuery;
use App\Statistics\CaseFlow\Infrastructure\Query\CaseFlowRegionalMetricsQuery;
use App\Tests\Statistics\Support\PreciseTransportTimeScenarios;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class CaseFlowRegionalMetricsQueryTest extends KernelTestCase
{
    use Factories;

    public function testAggregatesRegionalAndEmergencyMetricsForHospitalScope(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'case-flow-metrics-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'CaseFlowMetricsState']);
        $originArea = DispatchAreaFactory::createOne(['name' => 'CaseFlowOriginArea', 'state' => $state]);
        $otherArea = DispatchAreaFactory::createOne(['name' => 'CaseFlowOtherArea', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'CaseFlowMetricsHospital',
            'state' => $state,
            'dispatchArea' => $originArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'CaseFlowMetricsSpec']);
        DepartmentFactory::createOne(['name' => 'CaseFlowMetricsDept']);
        AssignmentFactory::createOne(['name' => 'CaseFlowMetricsAssign']);
        IndicationRawFactory::createOne(['name' => 'CaseFlowMetricsRaw', 'code' => 912_501]);

        $import = ImportFactory::createOne(['name' => 'CaseFlowMetricsImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createMany(7, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $originArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2026-03-01 08:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-03-01 08:30:00'),
        ]);

        AllocationFactory::createMany(3, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $otherArea,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'createdAt' => new \DateTimeImmutable('2026-03-02 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-03-02 10:20:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $scope = new StatisticsScopeCriteria([$hospital->getId()]);
        $metricsQuery = self::getContainer()->get(CaseFlowRegionalMetricsQuery::class);
        $originQuery = self::getContainer()->get(CaseFlowOriginDistributionQuery::class);

        $metrics = $metricsQuery->fetch(null, null, $scope);
        $origins = $originQuery->fetch(null, null, $scope);

        self::assertSame(10, $metrics->totalCases);
        self::assertSame(7, $metrics->regionalCases);
        self::assertSame(10, $metrics->fullTierCases);
        self::assertSame(7, $metrics->emergencyCases);
        self::assertNotNull($metrics->meanTransportMinutes);
        self::assertNotNull($metrics->medianTransportMinutes);

        self::assertCount(2, $origins);
        self::assertSame('CaseFlowOriginArea', $origins[0]->originName);
        self::assertSame(7, $origins[0]->caseCount);
        self::assertSame(7, $origins[0]->emergencyCount);
        self::assertSame('CaseFlowOtherArea', $origins[1]->originName);
        self::assertSame(3, $origins[1]->caseCount);
    }

    public function testReturnsZerosForEmptyHospitalScope(): void
    {
        self::bootKernel();

        $metrics = self::getContainer()->get(CaseFlowRegionalMetricsQuery::class)
            ->fetch(null, null, new StatisticsScopeCriteria([]));

        self::assertSame(0, $metrics->totalCases);
        self::assertSame(0, $metrics->regionalCases);
        self::assertNull($metrics->meanTransportMinutes);

        $origins = self::getContainer()->get(CaseFlowOriginDistributionQuery::class)
            ->fetch(null, null, new StatisticsScopeCriteria([]));

        self::assertSame([], $origins);
    }

    public function testTransportMetricsUsePreciseTimestampMinutes(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'case-flow-transport-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'CaseFlowTransportState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'CaseFlowTransportDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'CaseFlowTransportHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'CaseFlowTransportSpec']);
        DepartmentFactory::createOne(['name' => 'CaseFlowTransportDept']);
        AssignmentFactory::createOne(['name' => 'CaseFlowTransportAssign']);
        IndicationRawFactory::createOne(['name' => 'CaseFlowTransportRaw', 'code' => 912_502]);

        $import = ImportFactory::createOne(['name' => 'CaseFlowTransportImport', 'hospital' => $hospital, 'createdBy' => $user]);

        foreach (PreciseTransportTimeScenarios::allocations() as $times) {
            AllocationFactory::createOne([
                'import' => $import,
                'hospital' => $hospital,
                'state' => $state,
                'dispatchArea' => $dispatchArea,
                'createdAt' => $times['createdAt'],
                'arrivalAt' => $times['arrivalAt'],
            ]);
        }

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $metrics = self::getContainer()->get(CaseFlowRegionalMetricsQuery::class)
            ->fetch(null, null, new StatisticsScopeCriteria([$hospital->getId()]));

        self::assertEqualsWithDelta(
            PreciseTransportTimeScenarios::PRECISE_MEDIAN_MINUTES,
            $metrics->medianTransportMinutes,
            0.001,
        );
        self::assertEqualsWithDelta(
            PreciseTransportTimeScenarios::PRECISE_MEAN_MINUTES,
            $metrics->meanTransportMinutes,
            0.001,
        );
        self::assertNotEquals(
            PreciseTransportTimeScenarios::ROUNDED_MINUTES_MEDIAN,
            $metrics->medianTransportMinutes,
        );
    }

    public function testDispatchAreaScopeCountsInflowAndOutflow(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'case-flow-da-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'CaseFlowDaState']);
        $areaA = DispatchAreaFactory::createOne(['name' => 'CaseFlowDaAreaA', 'state' => $state]);
        $areaB = DispatchAreaFactory::createOne(['name' => 'CaseFlowDaAreaB', 'state' => $state]);
        $hospitalA = HospitalFactory::createOne([
            'name' => 'CaseFlowDaHospitalA',
            'state' => $state,
            'dispatchArea' => $areaA,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);
        $hospitalB = HospitalFactory::createOne([
            'name' => 'CaseFlowDaHospitalB',
            'state' => $state,
            'dispatchArea' => $areaB,
            'tier' => HospitalTier::BASIC,
            'location' => HospitalLocation::RURAL,
        ]);

        SpecialityFactory::createOne(['name' => 'CaseFlowDaSpec']);
        DepartmentFactory::createOne(['name' => 'CaseFlowDaDept']);
        AssignmentFactory::createOne(['name' => 'CaseFlowDaAssign']);
        IndicationRawFactory::createOne(['name' => 'CaseFlowDaRaw', 'code' => 912_503]);

        $importA = ImportFactory::createOne(['name' => 'CaseFlowDaImportA', 'hospital' => $hospitalA, 'createdBy' => $user]);
        $importB = ImportFactory::createOne(['name' => 'CaseFlowDaImportB', 'hospital' => $hospitalB, 'createdBy' => $user]);

        AllocationFactory::createMany(7, [
            'import' => $importA,
            'hospital' => $hospitalA,
            'state' => $state,
            'dispatchArea' => $areaA,
            'createdAt' => new \DateTimeImmutable('2026-03-01 08:00:00'),
        ]);
        AllocationFactory::createMany(3, [
            'import' => $importA,
            'hospital' => $hospitalA,
            'state' => $state,
            'dispatchArea' => $areaB,
            'createdAt' => new \DateTimeImmutable('2026-03-02 10:00:00'),
        ]);
        AllocationFactory::createMany(4, [
            'import' => $importB,
            'hospital' => $hospitalB,
            'state' => $state,
            'dispatchArea' => $areaA,
            'createdAt' => new \DateTimeImmutable('2026-03-03 11:00:00'),
        ]);

        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->rebuildForImport($importA->getId());
        $rebuilder->rebuildForImport($importB->getId());

        $scope = new StatisticsScopeCriteria(hospitalIds: null, dispatchAreaId: $areaA->getId());
        $metricsQuery = self::getContainer()->get(CaseFlowRegionalMetricsQuery::class);
        $originQuery = self::getContainer()->get(CaseFlowOriginDistributionQuery::class);

        $metrics = $metricsQuery->fetch(null, null, $scope);
        $catchmentOrigins = $originQuery->fetch(
            null,
            null,
            $scope,
            null,
            null,
            CaseFlowDispatchAreaMatch::Catchment,
        );

        self::assertSame(14, $metrics->totalCases);
        self::assertSame(7, $metrics->regionalCases);
        self::assertSame(3, $metrics->inflowCases);
        self::assertSame(4, $metrics->outflowCases);

        $catchmentByName = [];
        foreach ($catchmentOrigins as $row) {
            $catchmentByName[$row->originName] = $row->caseCount;
        }
        self::assertSame(7, $catchmentByName['CaseFlowDaAreaA']);
        self::assertSame(3, $catchmentByName['CaseFlowDaAreaB']);
    }
}
