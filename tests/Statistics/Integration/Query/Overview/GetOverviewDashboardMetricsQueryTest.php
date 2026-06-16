<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\Overview;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
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
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Infrastructure\Query\Overview\GetOverviewDashboardMetricsQuery;
use App\Statistics\Infrastructure\Query\Overview\OverviewQueryCriteria;
use App\Statistics\Infrastructure\Query\ProjectionFeatureQuery;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class GetOverviewDashboardMetricsQueryTest extends KernelTestCase
{
    use Factories;

    public function testBundleMatchesLegacyOverviewQueriesForScopedHospital(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'dashboard-metrics-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'DashboardMetricsState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'DashboardMetricsDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'DashboardMetricsHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'DashboardMetricsSpec']);
        DepartmentFactory::createOne(['name' => 'DashboardMetricsDept']);
        AssignmentFactory::createOne(['name' => 'DashboardMetricsAssign']);
        OccasionFactory::createOne(['name' => 'DashboardMetricsOcc']);
        SecondaryTransportFactory::createOne(['name' => 'DashboardMetricsSec']);
        InfectionFactory::createOne(['name' => 'DashboardMetricsInf']);
        IndicationRawFactory::createOne(['name' => 'DashboardMetricsRaw', 'code' => 912_350]);
        $indicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'DashboardMetricsNorm']);

        $import = ImportFactory::createOne(['name' => 'DashboardMetricsImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'transportType' => AllocationTransportType::GROUND,
            'age' => 40,
            'requiresResus' => true,
            'requiresCathlab' => false,
            'isCPR' => false,
            'isVentilated' => true,
            'isWorkAccident' => false,
            'isWithPhysician' => true,
            'occasion' => null,
            'infection' => null,
            'indicationNormalized' => $indicationNormalized,
            'createdAt' => new \DateTimeImmutable('2025-04-01 08:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-04-01 08:30:00'),
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
        $hospitalIds = [$hospital->getId()];
        $scopedCriteria = OverviewQueryCriteria::fromPeriodBounds($bounds, $hospitalIds);
        $periodCriteria = OverviewQueryCriteria::fromPeriodBounds($bounds, null);

        $container = self::getContainer();
        $legacy = new OverviewLegacyQueryFactory(
            $container->get(Connection::class),
            $container->get(ProjectionFeatureQuery::class),
        );

        $bundle = (new GetOverviewDashboardMetricsQuery(
            $container->get(Connection::class),
            $container->get(ProjectionFeatureQuery::class),
        ))($scopedCriteria);

        $summary = $legacy->summary()($scopedCriteria);
        $totals = $legacy->scopedTotals()($periodCriteria, $hospitalIds);
        $gender = $legacy->gender()($scopedCriteria);
        $urgency = $legacy->urgency()($scopedCriteria);

        self::assertSame($totals->platformTotal, $bundle->platformTotal);
        self::assertSame($totals->scopedTotal, $bundle->scopedTotal);
        self::assertSame($summary->total, $bundle->scopedTotal);
        self::assertSame($summary->withPhysician, $bundle->withPhysician);
        self::assertSame($summary->cpr, $bundle->cpr);
        self::assertSame($summary->ventilated, $bundle->ventilated);
        self::assertSame($summary->infectious, $bundle->infectious);
        self::assertSame($summary->cathlab, $bundle->cathlab);
        self::assertSame($summary->resus, $bundle->resus);
        self::assertSame($this->normalizedGender($gender), $bundle->genderCounts);
        self::assertSame($this->normalizedUrgency($urgency), $bundle->urgencyCounts);
    }

    /**
     * @param array<string, int> $raw
     *
     * @return array<string, int>
     */
    private function normalizedGender(array $raw): array
    {
        return [
            'M' => $raw['M'] ?? 0,
            'F' => $raw['F'] ?? 0,
            'X' => $raw['X'] ?? 0,
        ];
    }

    /**
     * @param array<int, int> $raw
     *
     * @return array<int, int>
     */
    private function normalizedUrgency(array $raw): array
    {
        return [
            1 => $raw[1] ?? 0,
            2 => $raw[2] ?? 0,
            3 => $raw[3] ?? 0,
        ];
    }
}
