<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Benchmarking;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Statistics\Application\Cohort\HospitalCohortKey;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Benchmarking\Application\BenchmarkCriteriaFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class BenchmarkCriteriaFactoryTest extends KernelTestCase
{
    use Factories;

    private BenchmarkCriteriaFactory $factory;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->factory = self::getContainer()->get(BenchmarkCriteriaFactory::class);
    }

    public function testBuildsCriteriaWithStateAndDispatchLabels(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $state = StateFactory::createOne(['name' => 'CriteriaState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'CriteriaDispatch', 'state' => $state]);
        HospitalFactory::createOne(['owner' => $user, 'state' => $state, 'dispatchArea' => $dispatchArea]);

        $primaryFilter = new StatisticsFilter(
            StatisticsFilterScope::State,
            null,
            null,
            StatisticsFilterPeriod::Quarter,
            2024,
            null,
            2,
            stateId: $state->getId(),
        );
        $comparisonFilter = new StatisticsFilter(
            StatisticsFilterScope::DispatchArea,
            null,
            null,
            StatisticsFilterPeriod::Month,
            2024,
            6,
            null,
            dispatchAreaId: $dispatchArea->getId(),
        );

        $criteria = $this->factory->create(
            new StatisticsContext($user, $primaryFilter),
            $comparisonFilter,
        );

        self::assertSame('CriteriaState', $criteria->primaryScopeLabel);
        self::assertSame('CriteriaDispatch', $criteria->comparisonScopeLabel);
        self::assertSame('Q2 2024', $criteria->primaryPeriodLabel);
        self::assertSame('2024-06', $criteria->comparisonPeriodLabel);
        self::assertSame($state->getId(), $primaryFilter->stateId);
        self::assertSame($dispatchArea->getId(), $comparisonFilter->dispatchAreaId);
    }

    public function testBuildsCriteriaWithHospitalCohortAndHospitalScopeLabels(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $user]);
        $cohortKey = HospitalCohortKey::all()[0];

        $primaryFilter = new StatisticsFilter(
            StatisticsFilterScope::Hospital,
            $hospital->getId(),
            null,
            StatisticsFilterPeriod::AllTime,
        );
        $comparisonFilter = new StatisticsFilter(
            StatisticsFilterScope::HospitalCohort,
            null,
            $cohortKey,
            StatisticsFilterPeriod::All,
        );

        $criteria = $this->factory->create(
            new StatisticsContext($user, $primaryFilter),
            $comparisonFilter,
        );

        self::assertSame(sprintf('Hospital %d', $hospital->getId()), $criteria->primaryScopeLabel);
        self::assertNotSame('', $criteria->comparisonScopeLabel);
        self::assertSame('All time', $criteria->primaryPeriodLabel);
        self::assertSame('Last 12 months', $criteria->comparisonPeriodLabel);
    }

    public function testUsesFallbackLabelsForUnknownRegionIds(): void
    {
        $primaryFilter = new StatisticsFilter(
            StatisticsFilterScope::State,
            null,
            null,
            StatisticsFilterPeriod::All,
            stateId: 999_999,
        );
        $comparisonFilter = new StatisticsFilter(
            StatisticsFilterScope::DispatchArea,
            null,
            null,
            StatisticsFilterPeriod::AllTime,
            dispatchAreaId: 888_888,
        );

        $criteria = $this->factory->create(
            new StatisticsContext(null, $primaryFilter),
            $comparisonFilter,
        );

        self::assertSame('State 999999', $criteria->primaryScopeLabel);
        self::assertSame('Dispatch area 888888', $criteria->comparisonScopeLabel);
    }
}
