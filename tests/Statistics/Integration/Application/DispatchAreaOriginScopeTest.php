<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Application;

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
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAllocationAnalysisQuery;
use App\Statistics\Infrastructure\Query\Overview\GetOverviewDashboardMetricsQuery;
use App\Statistics\Infrastructure\Query\Overview\OverviewQueryCriteria;
use App\Statistics\Infrastructure\Query\ProjectionTimeSeriesQuery;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class DispatchAreaOriginScopeTest extends KernelTestCase
{
    use Factories;

    public function testMultiDispatchAreaHospitalCountsByOriginNotHospitalPortfolio(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'dispatch-origin-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'DispatchOriginState']);
        $dispatchAreaA = DispatchAreaFactory::createOne(['name' => 'DispatchOriginA', 'state' => $state]);
        $dispatchAreaB = DispatchAreaFactory::createOne(['name' => 'DispatchOriginB', 'state' => $state]);

        $sharedHospital = HospitalFactory::createOne([
            'name' => 'DispatchOriginSharedHospital',
            'state' => $state,
            'dispatchArea' => $dispatchAreaA,
            'tier' => HospitalTier::BASIC,
            'location' => HospitalLocation::URBAN,
        ]);
        $hospitalOnlyA = HospitalFactory::createOne([
            'name' => 'DispatchOriginHospitalOnlyA',
            'state' => $state,
            'dispatchArea' => $dispatchAreaA,
            'tier' => HospitalTier::BASIC,
            'location' => HospitalLocation::URBAN,
        ]);
        $hospitalOnlyB = HospitalFactory::createOne([
            'name' => 'DispatchOriginHospitalOnlyB',
            'state' => $state,
            'dispatchArea' => $dispatchAreaB,
            'tier' => HospitalTier::BASIC,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'DispatchOriginSpec']);
        DepartmentFactory::createOne(['name' => 'DispatchOriginDept']);
        AssignmentFactory::createOne(['name' => 'DispatchOriginAssign']);
        OccasionFactory::createOne(['name' => 'DispatchOriginOcc']);
        SecondaryTransportFactory::createOne(['name' => 'DispatchOriginSec']);
        InfectionFactory::createOne(['name' => 'DispatchOriginInf']);
        IndicationRawFactory::createOne(['name' => 'DispatchOriginRaw', 'code' => 912_415]);
        $indicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'DispatchOriginNorm']);

        $importShared = ImportFactory::createOne([
            'name' => 'DispatchOriginImportShared',
            'hospital' => $sharedHospital,
            'createdBy' => $user,
        ]);
        $importOnlyA = ImportFactory::createOne([
            'name' => 'DispatchOriginImportOnlyA',
            'hospital' => $hospitalOnlyA,
            'createdBy' => $user,
        ]);
        $importOnlyB = ImportFactory::createOne([
            'name' => 'DispatchOriginImportOnlyB',
            'hospital' => $hospitalOnlyB,
            'createdBy' => $user,
        ]);

        $defaults = [
            'state' => $state,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'transportType' => AllocationTransportType::GROUND,
            'age' => 40,
            'requiresResus' => false,
            'requiresCathlab' => false,
            'isCPR' => false,
            'isVentilated' => false,
            'isWorkAccident' => false,
            'isWithPhysician' => false,
            'occasion' => null,
            'infection' => null,
            'indicationNormalized' => $indicationNormalized,
            'createdAt' => new \DateTimeImmutable('2025-04-01 08:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-04-01 08:30:00'),
        ];

        // Shared hospital receives cases from both dispatch areas A and B.
        AllocationFactory::createOne(array_merge($defaults, [
            'import' => $importShared,
            'hospital' => $sharedHospital,
            'dispatchArea' => $dispatchAreaA,
        ]));
        AllocationFactory::createOne(array_merge($defaults, [
            'import' => $importShared,
            'hospital' => $sharedHospital,
            'dispatchArea' => $dispatchAreaA,
        ]));
        AllocationFactory::createOne(array_merge($defaults, [
            'import' => $importShared,
            'hospital' => $sharedHospital,
            'dispatchArea' => $dispatchAreaB,
        ]));

        // Second hospital per dispatch area (eligibility / volume).
        AllocationFactory::createOne(array_merge($defaults, [
            'import' => $importOnlyA,
            'hospital' => $hospitalOnlyA,
            'dispatchArea' => $dispatchAreaA,
        ]));
        AllocationFactory::createOne(array_merge($defaults, [
            'import' => $importOnlyB,
            'hospital' => $hospitalOnlyB,
            'dispatchArea' => $dispatchAreaB,
        ]));

        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->rebuildForImport($importShared->getId());
        $rebuilder->rebuildForImport($importOnlyA->getId());
        $rebuilder->rebuildForImport($importOnlyB->getId());

        $scopeResolver = self::getContainer()->get(StatisticsScopeResolver::class);
        $timeSeriesQuery = self::getContainer()->get(ProjectionTimeSeriesQuery::class);
        $overviewMetricsQuery = self::getContainer()->get(GetOverviewDashboardMetricsQuery::class);
        $analysisQuery = self::getContainer()->get(GenericAllocationAnalysisQuery::class);

        $filterA = new StatisticsFilter(
            StatisticsFilterScope::DispatchArea,
            null,
            null,
            StatisticsFilterPeriod::AllTime,
            dispatchAreaId: $dispatchAreaA->getId(),
        );
        $filterB = new StatisticsFilter(
            StatisticsFilterScope::DispatchArea,
            null,
            null,
            StatisticsFilterPeriod::AllTime,
            dispatchAreaId: $dispatchAreaB->getId(),
        );
        $filterHospital = new StatisticsFilter(
            StatisticsFilterScope::Hospital,
            $sharedHospital->getId(),
            null,
            StatisticsFilterPeriod::AllTime,
        );

        $criteriaA = $scopeResolver->resolveCriteria(new StatisticsContext(null, $filterA));
        $criteriaB = $scopeResolver->resolveCriteria(new StatisticsContext(null, $filterB));
        $criteriaHospital = $scopeResolver->resolveCriteria(new StatisticsContext(null, $filterHospital));

        self::assertNull($criteriaA->hospitalIds);
        self::assertSame($dispatchAreaA->getId(), $criteriaA->dispatchAreaId);
        self::assertNull($criteriaB->hospitalIds);
        self::assertSame($dispatchAreaB->getId(), $criteriaB->dispatchAreaId);

        $countA = $timeSeriesQuery->countCreatedInPeriod(
            null,
            null,
            $criteriaA->hospitalIds,
            null,
            $criteriaA->dispatchAreaId,
        );
        $countB = $timeSeriesQuery->countCreatedInPeriod(
            null,
            null,
            $criteriaB->hospitalIds,
            null,
            $criteriaB->dispatchAreaId,
        );
        $countSharedHospital = $timeSeriesQuery->countCreatedInPeriod(
            null,
            null,
            $criteriaHospital->hospitalIds,
        );

        // A: 2 shared + 1 onlyA = 3; B: 1 shared + 1 onlyB = 2; shared hospital: 2A + 1B = 3
        self::assertSame(3, $countA);
        self::assertSame(2, $countB);
        self::assertSame(3, $countSharedHospital);

        $overviewA = ($overviewMetricsQuery)(OverviewQueryCriteria::fromPeriodBounds(
            new StatisticsPeriodBounds(null),
            $criteriaA->hospitalIds,
            $criteriaA->dispatchAreaId,
        ));
        $overviewB = ($overviewMetricsQuery)(OverviewQueryCriteria::fromPeriodBounds(
            new StatisticsPeriodBounds(null),
            $criteriaB->hospitalIds,
            $criteriaB->dispatchAreaId,
        ));

        self::assertSame(3, $overviewA->scopedTotal);
        self::assertSame(2, $overviewB->scopedTotal);

        $explorerA = $analysisQuery->execute(new AnalysisQuery(
            primaryDimensionKey: 'month',
            scopeCriteria: $criteriaA,
            periodBounds: new StatisticsPeriodBounds(null),
        ));
        $explorerB = $analysisQuery->execute(new AnalysisQuery(
            primaryDimensionKey: 'month',
            scopeCriteria: $criteriaB,
            periodBounds: new StatisticsPeriodBounds(null),
        ));

        self::assertSame($overviewA->scopedTotal, $explorerA->grandTotal);
        self::assertSame($overviewB->scopedTotal, $explorerB->grandTotal);
    }
}
