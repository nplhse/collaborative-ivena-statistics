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
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Infrastructure\Query\IndicationDashboard\IndicationDashboardMetricsQuery;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class IndicationDashboardMetricsQueryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

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
        $row = $query->fetch($targetIndication->getId(), $bounds->from, $bounds->toExclusive, $scope);

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

    public function testReturnsEmptyRowForEmptyHospitalScope(): void
    {
        self::bootKernel();

        $query = self::getContainer()->get(IndicationDashboardMetricsQuery::class);
        $row = $query->fetch(1, null, null, new StatisticsScopeCriteria([]));

        self::assertSame(0, $row->totalIndication);
        self::assertSame(0, $row->totalBaseline);
        self::assertNull($row->medianAgeIndication);
        self::assertNull($row->medianAgeBaseline);
    }
}
