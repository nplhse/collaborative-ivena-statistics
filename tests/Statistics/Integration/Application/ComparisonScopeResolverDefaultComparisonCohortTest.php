<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Application;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Statistics\Application\Cohort\HospitalCohortKey;
use App\Statistics\Application\ComparisonScopeResolver;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Tests\Support\MaterializedView\RefreshesStatisticsMaterializedViewsTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ComparisonScopeResolverDefaultComparisonCohortTest extends KernelTestCase
{
    use Factories;
    use RefreshesStatisticsMaterializedViewsTrait;

    public function testUsesPrimaryHospitalCohortWhenPrimaryScopeIsHospitalCohort(): void
    {
        self::bootKernel();

        $cohortKey = new HospitalCohortKey(HospitalLocation::RURAL, HospitalTier::EXTENDED);

        self::assertSame(
            'rural_extended',
            $this->defaultComparisonCohort(
                new StatisticsFilter(
                    StatisticsFilterScope::HospitalCohort,
                    null,
                    $cohortKey,
                    StatisticsFilterPeriod::All,
                ),
            ),
        );
    }

    public function testUsesDominantLocationTierWhenPrimaryScopeHasHospitals(): void
    {
        self::bootKernel();

        $context = $this->seedUrbanFullStateHospitals();
        $this->refreshStatisticsMaterializedViews();

        self::assertSame(
            'urban_full',
            $this->defaultComparisonCohort(
                new StatisticsFilter(
                    StatisticsFilterScope::State,
                    null,
                    null,
                    StatisticsFilterPeriod::All,
                    stateId: $context['stateId'],
                ),
            ),
        );
    }

    public function testFallsBackToFirstCohortWhenDominantLocationTierIsUnavailable(): void
    {
        self::bootKernel();

        $context = $this->seedUrbanFullStateHospitals(includeAllocations: false);
        $this->refreshStatisticsMaterializedViews();

        self::assertSame(
            'urban_basic',
            $this->defaultComparisonCohort(
                new StatisticsFilter(
                    StatisticsFilterScope::State,
                    null,
                    null,
                    StatisticsFilterPeriod::All,
                    stateId: $context['stateId'],
                ),
            ),
        );
    }

    private function defaultComparisonCohort(StatisticsFilter $primaryFilter): string
    {
        $resolver = self::getContainer()->get(ComparisonScopeResolver::class);
        $method = new \ReflectionMethod(ComparisonScopeResolver::class, 'defaultComparisonCohort');

        return $method->invoke($resolver, $primaryFilter, null, HospitalPermission::Statistics);
    }

    /**
     * @return array{stateId: int}
     */
    private function seedUrbanFullStateHospitals(bool $includeAllocations = true): array
    {
        $user = \App\User\Domain\Factory\UserFactory::createOne(['username' => 'comparison-cohort-'.bin2hex(random_bytes(4))]);
        $state = \App\Allocation\Infrastructure\Factory\StateFactory::createOne(['name' => 'ComparisonCohortState']);
        $dispatchArea = \App\Allocation\Infrastructure\Factory\DispatchAreaFactory::createOne(['name' => 'ComparisonCohortDispatch', 'state' => $state]);
        $hospitalA = \App\Allocation\Infrastructure\Factory\HospitalFactory::createOne([
            'name' => 'ComparisonCohortHospitalA',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);
        $hospitalB = \App\Allocation\Infrastructure\Factory\HospitalFactory::createOne([
            'name' => 'ComparisonCohortHospitalB',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        if (!$includeAllocations) {
            return ['stateId' => $state->getId()];
        }

        \App\Allocation\Infrastructure\Factory\SpecialityFactory::createOne(['name' => 'ComparisonCohortSpec']);
        \App\Allocation\Infrastructure\Factory\DepartmentFactory::createOne(['name' => 'ComparisonCohortDept']);
        \App\Allocation\Infrastructure\Factory\AssignmentFactory::createOne(['name' => 'ComparisonCohortAssign']);
        \App\Allocation\Infrastructure\Factory\OccasionFactory::createOne(['name' => 'ComparisonCohortOcc']);
        \App\Allocation\Infrastructure\Factory\InfectionFactory::createOne(['name' => 'ComparisonCohortInf']);
        \App\Allocation\Infrastructure\Factory\IndicationRawFactory::createOne(['name' => 'ComparisonCohortRaw', 'code' => 912_349]);
        $indicationNormalized = \App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory::createOne(['name' => 'ComparisonCohortNorm']);

        $importA = \App\Import\Infrastructure\Factory\ImportFactory::createOne(['name' => 'ComparisonCohortImportA', 'hospital' => $hospitalA, 'createdBy' => $user]);
        $importB = \App\Import\Infrastructure\Factory\ImportFactory::createOne(['name' => 'ComparisonCohortImportB', 'hospital' => $hospitalB, 'createdBy' => $user]);

        $allocationDefaults = [
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => \App\Allocation\Domain\Enum\AllocationGender::MALE,
            'urgency' => \App\Allocation\Domain\Enum\AllocationUrgency::EMERGENCY,
            'transportType' => \App\Allocation\Domain\Enum\AllocationTransportType::GROUND,
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

        \App\Allocation\Infrastructure\Factory\AllocationFactory::createOne(array_merge($allocationDefaults, ['import' => $importA, 'hospital' => $hospitalA]));
        \App\Allocation\Infrastructure\Factory\AllocationFactory::createOne(array_merge($allocationDefaults, ['import' => $importB, 'hospital' => $hospitalB]));

        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->rebuildForImport($importA->getId());
        $rebuilder->rebuildForImport($importB->getId());

        return ['stateId' => $state->getId()];
    }
}
