<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\InsightCompare;

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
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Infrastructure\Query\InsightCompare\InsightCompareMetricsQuery;
use App\Tests\Statistics\Support\PreciseTransportTimeScenarios;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class InsightCompareMetricsQueryTest extends KernelTestCase
{
    use Factories;

    public function testCountsTwoIndicationsInOneScan(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'indication-compare-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'InsightCompareState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'InsightCompareDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'InsightCompareHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'InsightCompareSpec']);
        DepartmentFactory::createOne(['name' => 'InsightCompareDept']);
        AssignmentFactory::createOne(['name' => 'InsightCompareAssign']);
        IndicationRawFactory::createOne(['name' => 'InsightCompareRaw', 'code' => 912_360]);

        $indicationA = IndicationNormalizedFactory::createOne(['name' => 'Compare A']);
        $indicationB = IndicationNormalizedFactory::createOne(['name' => 'Compare B']);

        $import = ImportFactory::createOne(['name' => 'InsightCompareImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createMany(4, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'isWithPhysician' => true,
            'indicationNormalized' => $indicationA,
            'createdAt' => new \DateTimeImmutable('2026-05-01 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-05-01 10:20:00'),
        ]);

        AllocationFactory::createMany(2, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'indicationNormalized' => $indicationB,
            'createdAt' => new \DateTimeImmutable('2026-05-02 11:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-05-02 11:20:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $filter = new StatisticsFilter(StatisticsFilterScope::Hospital, $hospital->getId(), null, StatisticsFilterPeriod::All);
        $bounds = StatisticsPeriodResolver::resolve($filter);
        $scope = new StatisticsScopeCriteria([$hospital->getId()]);

        $result = self::getContainer()->get(InsightCompareMetricsQuery::class)->fetch(
            [$indicationA->getId()],
            [$indicationB->getId()],
            $scope,
            $bounds,
            $scope,
            $bounds,
        );

        self::assertSame(4, $result->sideA->total);
        self::assertSame(2, $result->sideB->total);
        self::assertSame(4, $result->sideA->withPhysician);
        self::assertSame(4, $result->sideA->male);
        self::assertSame(2, $result->sideB->female);
    }

    public function testReturnsEmptyAggregationForEmptyHospitalScope(): void
    {
        self::bootKernel();

        $result = self::getContainer()->get(InsightCompareMetricsQuery::class)->fetch(
            [1],
            [2],
            new StatisticsScopeCriteria([]),
            new StatisticsPeriodBounds(null),
            new StatisticsScopeCriteria([]),
            new StatisticsPeriodBounds(null),
        );

        self::assertSame(0, $result->sideA->total);
        self::assertSame(0, $result->sideB->total);
    }

    public function testAggregatesGroupIndicationIds(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'indication-compare-group-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'InsightCompareGroupState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'InsightCompareGroupDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'InsightCompareGroupHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'InsightCompareGroupSpec']);
        DepartmentFactory::createOne(['name' => 'InsightCompareGroupDept']);
        AssignmentFactory::createOne(['name' => 'InsightCompareGroupAssign']);
        IndicationRawFactory::createOne(['name' => 'InsightCompareGroupRaw', 'code' => 912_362]);

        $indicationA = IndicationNormalizedFactory::createOne(['name' => 'Group Member A']);
        $indicationB = IndicationNormalizedFactory::createOne(['name' => 'Group Member B']);
        $indicationC = IndicationNormalizedFactory::createOne(['name' => 'Single Compare C']);

        $import = ImportFactory::createOne(['name' => 'InsightCompareGroupImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createMany(3, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationNormalized' => $indicationA,
            'createdAt' => new \DateTimeImmutable('2026-05-01 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-05-01 10:20:00'),
        ]);

        AllocationFactory::createMany(2, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationNormalized' => $indicationB,
            'createdAt' => new \DateTimeImmutable('2026-05-02 11:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-05-02 11:20:00'),
        ]);

        AllocationFactory::createMany(5, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationNormalized' => $indicationC,
            'createdAt' => new \DateTimeImmutable('2026-05-03 12:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-05-03 12:20:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $filter = new StatisticsFilter(StatisticsFilterScope::Hospital, $hospital->getId(), null, StatisticsFilterPeriod::All);
        $bounds = StatisticsPeriodResolver::resolve($filter);
        $scope = new StatisticsScopeCriteria([$hospital->getId()]);

        $groupIds = [$indicationA->getId(), $indicationB->getId()];
        $result = self::getContainer()->get(InsightCompareMetricsQuery::class)->fetch(
            $groupIds,
            [$indicationC->getId()],
            $scope,
            $bounds,
            $scope,
            $bounds,
        );

        self::assertSame(5, $result->sideA->total);
        self::assertSame(5, $result->sideB->total);
    }

    public function testMeanTransportUsesPreciseTimestampMinutes(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'indication-compare-transport-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'InsightCompareTransportState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'InsightCompareTransportDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'InsightCompareTransportHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'InsightCompareTransportSpec']);
        DepartmentFactory::createOne(['name' => 'InsightCompareTransportDept']);
        AssignmentFactory::createOne(['name' => 'InsightCompareTransportAssign']);
        IndicationRawFactory::createOne(['name' => 'InsightCompareTransportRaw', 'code' => 912_361]);

        $indicationA = IndicationNormalizedFactory::createOne(['name' => 'Compare Transport A']);
        $indicationB = IndicationNormalizedFactory::createOne(['name' => 'Compare Transport B']);

        $import = ImportFactory::createOne(['name' => 'InsightCompareTransportImport', 'hospital' => $hospital, 'createdBy' => $user]);

        foreach (PreciseTransportTimeScenarios::allocations() as $times) {
            AllocationFactory::createOne([
                'import' => $import,
                'hospital' => $hospital,
                'state' => $state,
                'dispatchArea' => $dispatchArea,
                'indicationNormalized' => $indicationA,
                'createdAt' => $times['createdAt'],
                'arrivalAt' => $times['arrivalAt'],
            ]);
        }

        AllocationFactory::createMany(2, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationNormalized' => $indicationB,
            'createdAt' => new \DateTimeImmutable('2026-05-02 11:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-05-02 11:20:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $scope = new StatisticsScopeCriteria([$hospital->getId()]);
        $result = self::getContainer()->get(InsightCompareMetricsQuery::class)->fetch(
            [$indicationA->getId()],
            [$indicationB->getId()],
            $scope,
            new StatisticsPeriodBounds(null),
            $scope,
            new StatisticsPeriodBounds(null),
        );

        self::assertEqualsWithDelta(
            PreciseTransportTimeScenarios::PRECISE_MEAN_MINUTES,
            $result->sideA->meanTransportMinutes,
            0.001,
        );
        self::assertNotEquals(
            PreciseTransportTimeScenarios::ROUNDED_MINUTES_MEDIAN,
            $result->sideA->meanTransportMinutes,
        );
        self::assertSame(20.0, $result->sideB->meanTransportMinutes);
    }

    public function testCountsSeparatePeriodWindowsPerSide(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'indication-compare-periods-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'InsightComparePeriodState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'InsightComparePeriodDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'InsightComparePeriodHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'InsightComparePeriodSpec']);
        DepartmentFactory::createOne(['name' => 'InsightComparePeriodDept']);
        AssignmentFactory::createOne(['name' => 'InsightComparePeriodAssign']);
        IndicationRawFactory::createOne(['name' => 'InsightComparePeriodRaw', 'code' => 912_365]);

        $indicationA = IndicationNormalizedFactory::createOne(['name' => 'Period A']);
        $indicationB = IndicationNormalizedFactory::createOne(['name' => 'Period B']);
        $import = ImportFactory::createOne(['name' => 'InsightComparePeriodImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createMany(5, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationNormalized' => $indicationA,
            'createdAt' => new \DateTimeImmutable('2026-03-01 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-03-01 10:20:00'),
        ]);
        AllocationFactory::createMany(2, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationNormalized' => $indicationB,
            'createdAt' => new \DateTimeImmutable('2025-03-01 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-03-01 10:20:00'),
        ]);
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $scope = new StatisticsScopeCriteria([$hospital->getId()]);
        $periodA = StatisticsPeriodResolver::resolve(new StatisticsFilter(
            StatisticsFilterScope::Hospital,
            $hospital->getId(),
            null,
            StatisticsFilterPeriod::Year,
            2026,
        ));
        $periodB = StatisticsPeriodResolver::resolve(new StatisticsFilter(
            StatisticsFilterScope::Hospital,
            $hospital->getId(),
            null,
            StatisticsFilterPeriod::Year,
            2025,
        ));

        $result = self::getContainer()->get(InsightCompareMetricsQuery::class)->fetch(
            [$indicationA->getId()],
            [$indicationB->getId()],
            $scope,
            $periodA,
            $scope,
            $periodB,
        );

        self::assertSame(5, $result->sideA->total);
        self::assertSame(2, $result->sideB->total);
    }
}
