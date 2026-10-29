<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\Application\Cohort\HospitalCohortKey;
use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisDimensionPolicy;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GenericAnalysisDimensionPolicyTest extends TestCase
{
    private GenericAnalysisDimensionPolicy $policy;

    private HospitalAccessInterface&\PHPUnit\Framework\MockObject\Stub $hospitalAccess;

    protected function setUp(): void
    {
        $this->hospitalAccess = $this->createStub(HospitalAccessInterface::class);
        $this->policy = new GenericAnalysisDimensionPolicy($this->hospitalAccess, new DimensionRegistry());
    }

    public function testPublicScopeDisallowsHospitalForParticipant(): void
    {
        $user = $this->createStub(User::class);
        $this->hospitalAccess->method('isAdminHospitalScopeUser')->willReturn(false);

        self::assertFalse($this->policy->isAllowed(
            'hospital',
            $this->filter(StatisticsFilterScope::Public),
            $user,
        ));
    }

    /**
     * @return iterable<string, array{StatisticsFilter}>
     */
    public static function nonPublicScopes(): iterable
    {
        yield 'my hospitals' => [new StatisticsFilter(StatisticsFilterScope::MyHospitals, null, null, StatisticsFilterPeriod::All)];
        yield 'hospital' => [new StatisticsFilter(StatisticsFilterScope::Hospital, 1, null, StatisticsFilterPeriod::All)];
        yield 'state' => [new StatisticsFilter(StatisticsFilterScope::State, null, null, StatisticsFilterPeriod::All, stateId: 1)];
        yield 'dispatch area' => [new StatisticsFilter(StatisticsFilterScope::DispatchArea, null, null, StatisticsFilterPeriod::All, dispatchAreaId: 1)];
        yield 'hospital cohort' => [new StatisticsFilter(StatisticsFilterScope::HospitalCohort, null, new HospitalCohortKey(\App\Allocation\Domain\Enum\HospitalLocation::RURAL, \App\Allocation\Domain\Enum\HospitalTier::BASIC), StatisticsFilterPeriod::All)];
    }

    #[DataProvider('nonPublicScopes')]
    public function testHospitalIsDeniedForParticipantInEveryScope(StatisticsFilter $filter): void
    {
        $user = $this->createStub(User::class);
        $this->hospitalAccess->method('isAdminHospitalScopeUser')->willReturn(false);

        self::assertFalse($this->policy->isAllowed('hospital', $filter, $user));
    }

    #[DataProvider('nonPublicScopes')]
    public function testHospitalIsDeniedWithoutUser(StatisticsFilter $filter): void
    {
        self::assertFalse($this->policy->isAllowed('hospital', $filter, null));
    }

    #[DataProvider('nonPublicScopes')]
    public function testHospitalIsAllowedForAdminInEveryScope(StatisticsFilter $filter): void
    {
        $user = $this->createStub(User::class);
        $this->hospitalAccess->method('isAdminHospitalScopeUser')->willReturn(true);

        self::assertTrue($this->policy->isAllowed('hospital', $filter, $user));
    }

    public function testAdminAllowsHospitalOnPublicScope(): void
    {
        $user = $this->createStub(User::class);
        $this->hospitalAccess->method('isAdminHospitalScopeUser')->willReturn(true);

        self::assertTrue($this->policy->isAllowed(
            'hospital',
            $this->filter(StatisticsFilterScope::Public),
            $user,
        ));
    }

    public function testStateDimensionRequiresStateScope(): void
    {
        $user = $this->createStub(User::class);
        $this->hospitalAccess->method('isAdminHospitalScopeUser')->willReturn(false);

        self::assertFalse($this->policy->isAllowed(
            'state',
            $this->filter(StatisticsFilterScope::Public),
            $user,
        ));
        self::assertTrue($this->policy->isAllowed(
            'state',
            $this->filter(StatisticsFilterScope::State, stateId: 1),
            $user,
        ));
    }

    public function testUnknownDimensionIsDenied(): void
    {
        self::assertFalse($this->policy->isAllowed(
            'unknown_dimension',
            $this->filter(StatisticsFilterScope::Public),
            null,
        ));
    }

    public function testRegisteredStandardDimensionIsAllowed(): void
    {
        self::assertTrue($this->policy->isAllowed(
            'hour',
            $this->filter(StatisticsFilterScope::Public),
            null,
        ));
    }

    public function testHospitalCohortDimensionAlwaysAllowed(): void
    {
        self::assertTrue($this->policy->isAllowed(
            'hospital_cohort',
            $this->filter(StatisticsFilterScope::Public),
            null,
        ));
    }

    private function filter(
        StatisticsFilterScope $scope,
        ?HospitalCohortKey $cohortType = null,
        ?int $stateId = null,
        ?int $dispatchAreaId = null,
    ): StatisticsFilter {
        return new StatisticsFilter(
            $scope,
            null,
            $cohortType,
            StatisticsFilterPeriod::All,
            stateId: $stateId,
            dispatchAreaId: $dispatchAreaId,
        );
    }
}
