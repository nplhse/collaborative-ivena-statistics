<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query;

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
use App\Statistics\Application\ComparisonScopeResolver;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\Mapping\AllocationStatsHospitalLocationProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsHospitalTierProjectionCode;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\Infrastructure\Query\AllocationStatsProjectionScopeQuery;
use App\Statistics\UI\Http\Controller\StatisticsPageViewModelFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AllocationStatsProjectionScopeQueryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testResolvesDistinctHospitalsForStateAndDispatchArea(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'scope-query-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'ScopeQueryState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'ScopeQueryDispatch', 'state' => $state]);
        $hospitalA = HospitalFactory::createOne([
            'name' => 'ScopeQueryHospitalA',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);
        $hospitalB = HospitalFactory::createOne([
            'name' => 'ScopeQueryHospitalB',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'ScopeQuerySpec']);
        DepartmentFactory::createOne(['name' => 'ScopeQueryDept']);
        AssignmentFactory::createOne(['name' => 'ScopeQueryAssign']);
        OccasionFactory::createOne(['name' => 'ScopeQueryOcc']);
        SecondaryTransportFactory::createOne(['name' => 'ScopeQuerySec']);
        InfectionFactory::createOne(['name' => 'ScopeQueryInf']);
        IndicationRawFactory::createOne(['name' => 'ScopeQueryRaw', 'code' => 912_347]);
        $indicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'ScopeQueryNorm']);

        $importA = ImportFactory::createOne(['name' => 'ScopeQueryImportA', 'hospital' => $hospitalA, 'createdBy' => $user]);
        $importB = ImportFactory::createOne(['name' => 'ScopeQueryImportB', 'hospital' => $hospitalB, 'createdBy' => $user]);

        $allocationDefaults = [
            'state' => $state,
            'dispatchArea' => $dispatchArea,
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

        AllocationFactory::createOne(array_merge($allocationDefaults, ['import' => $importA, 'hospital' => $hospitalA]));
        AllocationFactory::createOne(array_merge($allocationDefaults, ['import' => $importB, 'hospital' => $hospitalB]));

        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->rebuildForImport($importA->getId());
        $rebuilder->rebuildForImport($importB->getId());

        $query = self::getContainer()->get(AllocationStatsProjectionScopeQuery::class);
        $stateId = $state->getId();
        $dispatchAreaId = $dispatchArea->getId();

        self::assertSame([$hospitalA->getId(), $hospitalB->getId()], $query->distinctHospitalIdsForState($stateId));
        self::assertSame(2, $query->countDistinctHospitalsForState($stateId));
        self::assertContains($stateId, $query->stateIdsWithAtLeastDistinctHospitals(2));

        self::assertSame([$hospitalA->getId(), $hospitalB->getId()], $query->distinctHospitalIdsForDispatchArea($dispatchAreaId));
        self::assertSame(2, $query->countDistinctHospitalsForDispatchArea($dispatchAreaId));
        self::assertContains($dispatchAreaId, $query->dispatchAreaIdsWithAtLeastDistinctHospitals(2));

        $dominant = $query->dominantLocationTierForHospitalIds([$hospitalA->getId(), $hospitalB->getId()]);
        self::assertSame(
            [
                'location' => AllocationStatsHospitalLocationProjectionCode::Urban->value,
                'tier' => AllocationStatsHospitalTierProjectionCode::Full->value,
            ],
            $dominant,
        );

        $comparisonFilter = self::getContainer()->get(ComparisonScopeResolver::class)->resolve(
            new Request(query: ['comparison_scope' => sprintf('state:%d', $stateId)]),
            null,
            new StatisticsFilter(
                StatisticsFilterScope::State,
                null,
                null,
                StatisticsFilterPeriod::All,
                stateId: $stateId,
            ),
        );

        self::assertSame(StatisticsFilterScope::State, $comparisonFilter->scope);
        self::assertSame($stateId, $comparisonFilter->stateId);

        $scopeCriteria = self::getContainer()->get(StatisticsScopeResolver::class)->resolveCriteria(
            new StatisticsContext(
                null,
                new StatisticsFilter(
                    StatisticsFilterScope::State,
                    null,
                    null,
                    StatisticsFilterPeriod::All,
                    stateId: $stateId,
                ),
            ),
        );

        self::assertSame([$hospitalA->getId(), $hospitalB->getId()], $scopeCriteria->hospitalIds);

        $pageModel = self::getContainer()->get(StatisticsPageViewModelFactory::class)->create(
            new Request(query: ['scope' => sprintf('state:%d', $stateId), 'period' => 'all']),
            'app_stats_dashboard',
            null,
            new StatisticsFilter(
                StatisticsFilterScope::State,
                null,
                null,
                StatisticsFilterPeriod::All,
                stateId: $stateId,
            ),
        );

        self::assertTrue($pageModel->showScopeSecondaryPicker);
        self::assertSame('ScopeQueryState', $pageModel->headingScope);
    }
}
