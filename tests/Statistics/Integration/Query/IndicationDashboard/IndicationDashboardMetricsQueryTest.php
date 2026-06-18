<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\IndicationDashboard;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
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
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\Mapping\AllocationStatsDayTimeBucketProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsHospitalLocationProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsHospitalTierProjectionCode;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Infrastructure\Query\IndicationDashboard\IndicationDashboardMetricsQuery;
use App\Tests\Statistics\Support\PreciseTransportTimeScenarios;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class IndicationDashboardMetricsQueryTest extends KernelTestCase
{
    use Factories;

    public function testCountsIndicationAndBaselineWithDayTimeBucket(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'indication-metrics-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'IndicationMetricsState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'IndicationMetricsDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'IndicationMetricsHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'IndicationMetricsSpec']);
        DepartmentFactory::createOne(['name' => 'IndicationMetricsDept']);
        AssignmentFactory::createOne(['name' => 'IndicationMetricsAssign']);
        IndicationRawFactory::createOne(['name' => 'IndicationMetricsRaw', 'code' => 912_349]);

        $targetIndication = IndicationNormalizedFactory::createOne(['name' => 'STEMI Target']);
        $otherIndication = IndicationNormalizedFactory::createOne(['name' => 'Other Indication']);

        $import = ImportFactory::createOne(['name' => 'IndicationMetricsImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createMany(5, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'age' => 85,
            'isWithPhysician' => true,
            'requiresResus' => true,
            'indicationNormalized' => $targetIndication,
            'createdAt' => new \DateTimeImmutable('2026-03-01 02:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-03-01 02:30:00'),
        ]);

        AllocationFactory::createMany(10, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'age' => 40,
            'isWithPhysician' => false,
            'indicationNormalized' => $otherIndication,
            'createdAt' => new \DateTimeImmutable('2026-03-02 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-03-02 10:20:00'),
        ]);

        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->rebuildForImport($import->getId());

        $filter = new StatisticsFilter(
            StatisticsFilterScope::Hospital,
            $hospital->getId(),
            null,
            StatisticsFilterPeriod::All,
        );
        $bounds = StatisticsPeriodResolver::resolve($filter);
        $scope = new StatisticsScopeCriteria([$hospital->getId()]);

        $query = self::getContainer()->get(IndicationDashboardMetricsQuery::class);
        $row = $query->fetch([$targetIndication->getId()], $bounds->from, $bounds->toExclusive, $scope);

        self::assertSame(5, $row->totalIndication);
        self::assertSame(10, $row->totalBaseline);
        self::assertSame(5, $row->withPhysicianIndication);
        self::assertSame(5, $row->nightDaytimeIndication);
        self::assertGreaterThan($row->rate($row->withPhysicianBaseline, $row->totalBaseline), $row->rate($row->withPhysicianIndication, $row->totalIndication));

        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $nightBucket = $connection->fetchOne(
            'SELECT day_time_bucket_code FROM allocation_stats_projection WHERE indication_normalized_id = :id LIMIT 1',
            ['id' => $targetIndication->getId()],
        );
        self::assertSame((string) AllocationStatsDayTimeBucketProjectionCode::Night->value, (string) $nightBucket);
    }

    public function testFiltersByLocationAndTierScope(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'indication-metrics-scope-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'IndicationMetricsScopeState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'IndicationMetricsScopeDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'IndicationMetricsScopeHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'IndicationMetricsScopeSpec']);
        DepartmentFactory::createOne(['name' => 'IndicationMetricsScopeDept']);
        AssignmentFactory::createOne(['name' => 'IndicationMetricsScopeAssign']);
        IndicationRawFactory::createOne(['name' => 'IndicationMetricsScopeRaw', 'code' => 912_352]);

        $targetIndication = IndicationNormalizedFactory::createOne(['name' => 'Scope Target']);

        $import = ImportFactory::createOne(['name' => 'IndicationMetricsScopeImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationNormalized' => $targetIndication,
            'createdAt' => new \DateTimeImmutable('2026-03-01 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-03-01 10:20:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $scope = new StatisticsScopeCriteria(
            null,
            [AllocationStatsHospitalLocationProjectionCode::Urban->value],
            [AllocationStatsHospitalTierProjectionCode::Full->value],
        );

        $row = self::getContainer()->get(IndicationDashboardMetricsQuery::class)
            ->fetch([$targetIndication->getId()], null, null, $scope);

        self::assertSame(1, $row->totalIndication);
    }

    public function testReturnsEmptyRowForEmptyHospitalScope(): void
    {
        self::bootKernel();

        $query = self::getContainer()->get(IndicationDashboardMetricsQuery::class);
        $row = $query->fetch([1], null, null, new StatisticsScopeCriteria([]));

        self::assertSame(0, $row->totalIndication);
        self::assertSame(0, $row->totalBaseline);
        self::assertNull($row->medianAgeIndication);
        self::assertNull($row->medianAgeBaseline);
    }

    public function testMedianTransportUsesPreciseTimestampMinutes(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'indication-transport-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'IndicationTransportState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'IndicationTransportDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'IndicationTransportHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'IndicationTransportSpec']);
        DepartmentFactory::createOne(['name' => 'IndicationTransportDept']);
        AssignmentFactory::createOne(['name' => 'IndicationTransportAssign']);
        IndicationRawFactory::createOne(['name' => 'IndicationTransportRaw', 'code' => 912_351]);

        $targetIndication = IndicationNormalizedFactory::createOne(['name' => 'Transport Target']);
        $otherIndication = IndicationNormalizedFactory::createOne(['name' => 'Transport Other']);

        $import = ImportFactory::createOne(['name' => 'IndicationTransportImport', 'hospital' => $hospital, 'createdBy' => $user]);

        foreach (PreciseTransportTimeScenarios::allocations() as $times) {
            AllocationFactory::createOne([
                'import' => $import,
                'hospital' => $hospital,
                'state' => $state,
                'dispatchArea' => $dispatchArea,
                'indicationNormalized' => $targetIndication,
                'createdAt' => $times['createdAt'],
                'arrivalAt' => $times['arrivalAt'],
            ]);
        }

        AllocationFactory::createMany(2, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationNormalized' => $otherIndication,
            'createdAt' => new \DateTimeImmutable('2026-03-02 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-03-02 10:20:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $scope = new StatisticsScopeCriteria([$hospital->getId()]);
        $row = self::getContainer()->get(IndicationDashboardMetricsQuery::class)
            ->fetch([$targetIndication->getId()], null, null, $scope);

        self::assertEqualsWithDelta(
            PreciseTransportTimeScenarios::PRECISE_MEDIAN_MINUTES,
            $row->medianTransportMinutesIndication,
            0.001,
        );
        self::assertNotEquals(
            PreciseTransportTimeScenarios::ROUNDED_MINUTES_MEDIAN,
            $row->medianTransportMinutesIndication,
        );
        self::assertSame(20.0, $row->medianTransportMinutesBaseline);
    }
}
