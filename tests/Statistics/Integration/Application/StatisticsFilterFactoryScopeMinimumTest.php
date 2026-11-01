<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Application;

use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\State;
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
use App\Statistics\Application\DTO\StatisticsFilterInput;
use App\Statistics\Application\DTO\StatisticsFilterNotice;
use App\Statistics\Application\StatisticsFilterFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\Tests\Support\MaterializedView\RefreshesStatisticsMaterializedViewsTrait;
use App\User\Domain\Factory\UserFactory;

/**
 * State, dispatch area and cohort scopes need at least three hospitals (#934).
 *
 * Fixture: one allocation per hospital.
 * - State "Three" with dispatch area "Three": 3 urban full-care hospitals → scopes are used.
 * - State "Two" with dispatch area "Two": 2 rural basic-care hospitals → scopes fall back to public.
 */
final class StatisticsFilterFactoryScopeMinimumTest extends DatabaseKernelTestCase
{
    use RefreshesStatisticsMaterializedViewsTrait;

    private int $stateThreeId;

    private int $stateTwoId;

    private int $dispatchAreaThreeId;

    private int $dispatchAreaTwoId;

    public function testStateScopeWithThreeHospitalsIsUsed(): void
    {
        $this->createFixture();

        $filter = $this->createFilter('state:'.$this->stateThreeId);

        self::assertSame('state', $filter->scope->value);
        self::assertSame($this->stateThreeId, $filter->stateId);
        self::assertNull($filter->notice);
    }

    public function testStateScopeWithTwoHospitalsFallsBackToPublic(): void
    {
        $this->createFixture();

        $filter = $this->createFilter('state:'.$this->stateTwoId);

        self::assertSame('public', $filter->scope->value);
        self::assertNull($filter->stateId);
        self::assertSame(StatisticsFilterNotice::StateInvalid, $filter->notice);
    }

    public function testDispatchAreaScopeWithThreeHospitalsIsUsed(): void
    {
        $this->createFixture();

        $filter = $this->createFilter('dispatch_area:'.$this->dispatchAreaThreeId);

        self::assertSame('dispatch_area', $filter->scope->value);
        self::assertSame($this->dispatchAreaThreeId, $filter->dispatchAreaId);
        self::assertNull($filter->notice);
    }

    public function testDispatchAreaScopeWithTwoHospitalsFallsBackToPublic(): void
    {
        $this->createFixture();

        $filter = $this->createFilter('dispatch_area:'.$this->dispatchAreaTwoId);

        self::assertSame('public', $filter->scope->value);
        self::assertNull($filter->dispatchAreaId);
        self::assertSame(StatisticsFilterNotice::DispatchAreaInvalid, $filter->notice);
    }

    public function testCohortWithThreeHospitalsIsUsed(): void
    {
        $this->createFixture();

        $filter = $this->createFilter('hospital_cohort', 'urban_full');

        self::assertSame('hospital_cohort', $filter->scope->value);
        self::assertSame('urban_full', $filter->cohortType?->value());
        self::assertNull($filter->notice);
    }

    public function testCohortWithTwoHospitalsFallsBackToPublic(): void
    {
        $this->createFixture();

        $filter = $this->createFilter('hospital_cohort', 'rural_basic');

        self::assertSame('public', $filter->scope->value);
        self::assertNull($filter->cohortType);
        self::assertSame(StatisticsFilterNotice::CohortTooSmall, $filter->notice);
    }

    private function createFilter(string $scope, string $cohort = ''): StatisticsFilter
    {
        return self::getContainer()->get(StatisticsFilterFactory::class)->createFromInput(
            new StatisticsFilterInput($scope, '', $cohort, '', '', 'all', null, null, null, true),
            null,
        );
    }

    private function createFixture(): void
    {
        self::bootKernel();

        // Reference data the hospital and allocation factories pick from.
        UserFactory::createOne(['username' => 'scope-minimum-owner']);
        SpecialityFactory::createOne(['name' => 'ScopeMinimumSpec']);
        DepartmentFactory::createOne(['name' => 'ScopeMinimumDept']);
        AssignmentFactory::createOne(['name' => 'ScopeMinimumAssign']);
        OccasionFactory::createOne(['name' => 'ScopeMinimumOcc']);
        SecondaryTransportFactory::createOne(['name' => 'ScopeMinimumSec']);
        InfectionFactory::createOne(['name' => 'ScopeMinimumInf']);
        IndicationRawFactory::createOne(['name' => 'ScopeMinimumRaw', 'code' => 912_934]);
        IndicationNormalizedFactory::createOne(['name' => 'ScopeMinimumNorm']);

        $stateThree = StateFactory::createOne(['name' => 'ScopeMinimumStateThree']);
        $stateTwo = StateFactory::createOne(['name' => 'ScopeMinimumStateTwo']);
        $dispatchAreaThree = DispatchAreaFactory::createOne(['name' => 'ScopeMinimumDispatchThree', 'state' => $stateThree]);
        $dispatchAreaTwo = DispatchAreaFactory::createOne(['name' => 'ScopeMinimumDispatchTwo', 'state' => $stateTwo]);

        $importIds = [];
        for ($i = 1; $i <= 3; ++$i) {
            $importIds[] = $this->createHospitalWithAllocation('ScopeMinimumThree'.$i, $stateThree, $dispatchAreaThree, HospitalLocation::URBAN, HospitalTier::FULL);
        }
        for ($i = 1; $i <= 2; ++$i) {
            $importIds[] = $this->createHospitalWithAllocation('ScopeMinimumTwo'.$i, $stateTwo, $dispatchAreaTwo, HospitalLocation::RURAL, HospitalTier::BASIC);
        }

        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        foreach ($importIds as $importId) {
            $rebuilder->rebuildForImport($importId);
        }
        $this->refreshStatisticsMaterializedViews();

        $this->stateThreeId = (int) $stateThree->getId();
        $this->stateTwoId = (int) $stateTwo->getId();
        $this->dispatchAreaThreeId = (int) $dispatchAreaThree->getId();
        $this->dispatchAreaTwoId = (int) $dispatchAreaTwo->getId();
    }

    private function createHospitalWithAllocation(
        string $name,
        State $state,
        DispatchArea $dispatchArea,
        HospitalLocation $location,
        HospitalTier $tier,
    ): int {
        $hospital = HospitalFactory::createOne([
            'name' => $name,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'location' => $location,
            'tier' => $tier,
        ]);
        $import = ImportFactory::createOne([
            'name' => $name.'Import',
            'hospital' => $hospital,
            'createdBy' => UserFactory::createOne(['username' => strtolower($name)]),
        ]);
        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'createdAt' => new \DateTimeImmutable('2025-04-01 08:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-04-01 08:30:00'),
        ]);

        return (int) $import->getId();
    }
}
