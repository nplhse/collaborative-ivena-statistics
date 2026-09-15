<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\GeographicMap;

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
use App\Statistics\Application\DTO\StatisticsDrawerFilter;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegment;
use App\Statistics\CaseFlow\Infrastructure\Query\CaseFlowDispatchAreaMatch;
use App\Statistics\CaseFlow\Infrastructure\Query\GeographicSegmentDistributionQuery;
use App\Statistics\CaseFlow\Infrastructure\Query\GeographicSegmentMetricsQuery;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class GeographicSegmentMetricsQueryTest extends KernelTestCase
{
    use Factories;

    public function testTravelBandAndDrawerStayInsideHospitalScope(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'geo-seg-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'GeoSegState']);
        $home = DispatchAreaFactory::createOne(['name' => 'GeoSegHome', 'state' => $state]);
        $other = DispatchAreaFactory::createOne(['name' => 'GeoSegOther', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'GeoSegHospital',
            'state' => $state,
            'dispatchArea' => $home,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);
        $foreignHospital = HospitalFactory::createOne([
            'name' => 'GeoSegForeign',
            'state' => $state,
            'dispatchArea' => $other,
            'tier' => HospitalTier::BASIC,
            'location' => HospitalLocation::RURAL,
        ]);

        SpecialityFactory::createOne(['name' => 'GeoSegSpec']);
        DepartmentFactory::createOne(['name' => 'GeoSegDept']);
        AssignmentFactory::createOne(['name' => 'GeoSegAssign']);
        IndicationRawFactory::createOne(['name' => 'GeoSegRaw', 'code' => 912_701]);

        $import = ImportFactory::createOne(['name' => 'GeoSegImport', 'hospital' => $hospital, 'createdBy' => $user]);
        $foreignImport = ImportFactory::createOne(['name' => 'GeoSegForeignImport', 'hospital' => $foreignHospital, 'createdBy' => $user]);

        AllocationFactory::createMany(12, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $home,
            'urgency' => AllocationUrgency::OUTPATIENT,
            'createdAt' => new \DateTimeImmutable('2026-04-01 09:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 09:15:00'),
        ]);
        AllocationFactory::createMany(5, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $home,
            'urgency' => AllocationUrgency::OUTPATIENT,
            'createdAt' => new \DateTimeImmutable('2026-04-02 09:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-02 09:05:00'),
        ]);
        AllocationFactory::createMany(3, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $home,
            'urgency' => AllocationUrgency::OUTPATIENT,
            'createdAt' => new \DateTimeImmutable('2026-04-02 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-02 10:20:00'),
        ]);
        AllocationFactory::createMany(8, [
            'import' => $foreignImport,
            'hospital' => $foreignHospital,
            'state' => $state,
            'dispatchArea' => $home,
            'urgency' => AllocationUrgency::OUTPATIENT,
            'createdAt' => new \DateTimeImmutable('2026-04-03 09:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-03 09:15:00'),
        ]);

        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->rebuildForImport($import->getId());
        $rebuilder->rebuildForImport($foreignImport->getId());

        $query = self::getContainer()->get(GeographicSegmentMetricsQuery::class);
        $scope = new StatisticsScopeCriteria([$hospital->getId()]);
        $travel = GeographicSegment::travelTimeBand('10_20');
        $origin = GeographicSegment::originArea($home->getId());

        $travelMetrics = $query->fetch(null, null, $scope, $travel);
        $originMetrics = $query->fetch(null, null, $scope, $origin);
        $drawerMetrics = $query->fetch(
            null,
            null,
            $scope,
            $origin,
            null,
            new StatisticsDrawerFilter(urgency: 1),
        );

        self::assertSame(20, $travelMetrics->populationCases);
        self::assertSame(12, $travelMetrics->segmentCases);
        self::assertSame(20, $originMetrics->populationCases);
        self::assertSame(20, $originMetrics->segmentCases);
        self::assertSame(0, $drawerMetrics->populationCases);
        self::assertSame(0, $drawerMetrics->segmentCases);

        $entireArea = $query->fetch(null, null, $scope, null);
        self::assertSame(20, $entireArea->populationCases);
        self::assertSame(20, $entireArea->segmentCases);
    }

    public function testDispatchAreaCatchmentOriginDoesNotIncludeOutflow(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'geo-seg-da-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'GeoSegDaState']);
        $areaA = DispatchAreaFactory::createOne(['name' => 'GeoSegDaA', 'state' => $state]);
        $areaB = DispatchAreaFactory::createOne(['name' => 'GeoSegDaB', 'state' => $state]);
        $hospitalA = HospitalFactory::createOne([
            'name' => 'GeoSegDaHospitalA',
            'state' => $state,
            'dispatchArea' => $areaA,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);
        $hospitalB = HospitalFactory::createOne([
            'name' => 'GeoSegDaHospitalB',
            'state' => $state,
            'dispatchArea' => $areaB,
            'tier' => HospitalTier::BASIC,
            'location' => HospitalLocation::RURAL,
        ]);

        SpecialityFactory::createOne(['name' => 'GeoSegDaSpec']);
        DepartmentFactory::createOne(['name' => 'GeoSegDaDept']);
        AssignmentFactory::createOne(['name' => 'GeoSegDaAssign']);
        IndicationRawFactory::createOne(['name' => 'GeoSegDaRaw', 'code' => 912_702]);

        $importA = ImportFactory::createOne(['name' => 'GeoSegDaImportA', 'hospital' => $hospitalA, 'createdBy' => $user]);
        $importB = ImportFactory::createOne(['name' => 'GeoSegDaImportB', 'hospital' => $hospitalB, 'createdBy' => $user]);

        AllocationFactory::createMany(12, [
            'import' => $importA,
            'hospital' => $hospitalA,
            'state' => $state,
            'dispatchArea' => $areaA,
            'createdAt' => new \DateTimeImmutable('2026-04-01 09:00:00'),
        ]);
        AllocationFactory::createMany(4, [
            'import' => $importB,
            'hospital' => $hospitalB,
            'state' => $state,
            'dispatchArea' => $areaA,
            'createdAt' => new \DateTimeImmutable('2026-04-02 09:00:00'),
        ]);

        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->rebuildForImport($importA->getId());
        $rebuilder->rebuildForImport($importB->getId());

        $metrics = self::getContainer()->get(GeographicSegmentMetricsQuery::class)->fetch(
            null,
            null,
            new StatisticsScopeCriteria(hospitalIds: null, dispatchAreaId: $areaA->getId()),
            GeographicSegment::originArea($areaA->getId()),
            null,
            null,
            CaseFlowDispatchAreaMatch::Catchment,
        );

        self::assertSame(12, $metrics->populationCases);
        self::assertSame(12, $metrics->segmentCases);
    }

    public function testImpossibleHospitalScopeYieldsEmptyMetricsAndDistributions(): void
    {
        self::bootKernel();

        $scope = new StatisticsScopeCriteria([]);
        $metrics = self::getContainer()->get(GeographicSegmentMetricsQuery::class)->fetch(null, null, $scope, null);
        self::assertSame(0, $metrics->populationCases);
        self::assertSame(0, $metrics->segmentCases);
        self::assertNull($metrics->medianTransportMinutes);

        $distribution = self::getContainer()->get(GeographicSegmentDistributionQuery::class);
        self::assertSame([1 => 0, 2 => 0, 3 => 0], $distribution->fetchUrgencyCounts(null, null, $scope, null));
        self::assertSame([1 => 0, 2 => 0, 3 => 0], $distribution->fetchGenderCounts(null, null, $scope, null));
        self::assertSame([], $distribution->fetchAgeCounts(null, null, $scope, null));
        self::assertSame(
            ['resus' => 0, 'cathlab' => 0, 'with_physician' => 0, 'cpr' => 0, 'ventilation' => 0, 'shock' => 0],
            $distribution->fetchResourceCounts(null, null, $scope, null),
        );
    }
}
