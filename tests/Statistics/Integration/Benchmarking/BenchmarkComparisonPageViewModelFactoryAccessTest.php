<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Benchmarking;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Cohort\HospitalCohortKey;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Benchmarking\UI\Http\Controller\BenchmarkComparisonPageViewModelFactory;
use App\Tests\Support\MaterializedView\RefreshesStatisticsMaterializedViewsTrait;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class BenchmarkComparisonPageViewModelFactoryAccessTest extends KernelTestCase
{
    use Factories;
    use RefreshesStatisticsMaterializedViewsTrait;

    private BenchmarkComparisonPageViewModelFactory $factory;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->factory = self::getContainer()->get(BenchmarkComparisonPageViewModelFactory::class);
    }

    public function testParticipantSeesMyHospitalsMenuAndDualHospitalPicker(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospitalA = HospitalFactory::createOne(['name' => 'Comparison Hospital A', 'owner' => $user]);
        HospitalFactory::createOne(['name' => 'Comparison Hospital B', 'owner' => $user]);

        $model = $this->factory->create(
            new Request(query: [
                'scope' => 'my_hospitals',
                'comparison_scope' => 'hospital',
                'comparison_hospital' => (string) $hospitalA->getId(),
            ]),
            'app_stats_benchmarking',
            $user,
            new StatisticsFilter(
                StatisticsFilterScope::Hospital,
                $hospitalA->getId(),
                null,
                StatisticsFilterPeriod::AllTime,
            ),
        );

        self::assertTrue($this->hasMenuKey($model->scopePrimaryMenu, 'my_hospitals_group'));
        self::assertTrue($model->showScopeSecondaryPicker);
        self::assertCount(3, $model->scopeSecondaryMenu);
        self::assertSame('Hospital: Comparison Hospital A', $model->headingScope);
    }

    public function testShowsUnscopedHintForParticipantWithoutLinkedHospitals(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);

        $model = $this->factory->create(
            new Request(query: ['comparison_scope' => 'my_hospitals']),
            'app_stats_benchmarking',
            $user,
            new StatisticsFilter(StatisticsFilterScope::MyHospitals, null, null, StatisticsFilterPeriod::All),
        );

        self::assertTrue($model->showUnscopedHint);
        self::assertSame('Public', $model->headingScope);
    }

    public function testBuildsStateAndDispatchSecondaryMenusAfterMaterializedViewRefresh(): void
    {
        $user = UserFactory::createOne(['username' => 'comparison-vm-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'ComparisonVmState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'ComparisonVmDispatch', 'state' => $state]);
        $hospitalA = HospitalFactory::createOne([
            'name' => 'ComparisonVmHospitalA',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);
        $hospitalB = HospitalFactory::createOne([
            'name' => 'ComparisonVmHospitalB',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'ComparisonVmSpec']);
        DepartmentFactory::createOne(['name' => 'ComparisonVmDept']);
        AssignmentFactory::createOne(['name' => 'ComparisonVmAssign']);
        IndicationRawFactory::createOne(['name' => 'ComparisonVmRaw', 'code' => 912_402]);

        $importA = ImportFactory::createOne(['name' => 'ComparisonVmImportA', 'hospital' => $hospitalA, 'createdBy' => $user]);
        $importB = ImportFactory::createOne(['name' => 'ComparisonVmImportB', 'hospital' => $hospitalB, 'createdBy' => $user]);

        AllocationFactory::createOne([
            'import' => $importA,
            'hospital' => $hospitalA,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2025-06-01 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-06-01 10:20:00'),
        ]);
        AllocationFactory::createOne([
            'import' => $importB,
            'hospital' => $hospitalB,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'createdAt' => new \DateTimeImmutable('2025-06-02 11:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-06-02 11:20:00'),
        ]);

        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->rebuildForImport($importA->getId());
        $rebuilder->rebuildForImport($importB->getId());
        $this->refreshStatisticsMaterializedViews();

        $stateModel = $this->factory->create(
            new Request(query: [
                'comparison_scope' => 'state:'.$state->getId(),
            ]),
            'app_stats_benchmarking',
            null,
            new StatisticsFilter(
                StatisticsFilterScope::State,
                null,
                null,
                StatisticsFilterPeriod::Month,
                2025,
                6,
                stateId: $state->getId(),
            ),
        );

        self::assertTrue($this->hasMenuKey($stateModel->scopePrimaryMenu, 'state_group'));
        self::assertTrue($stateModel->showScopeSecondaryPicker);
        self::assertSame('ComparisonVmState', $stateModel->headingScope);
        self::assertNotNull($stateModel->scopeSecondaryDropdownLabel);

        $dispatchModel = $this->factory->create(
            new Request(query: [
                'comparison_scope' => 'dispatch_area:'.$dispatchArea->getId(),
            ]),
            'app_stats_benchmarking',
            null,
            new StatisticsFilter(
                StatisticsFilterScope::DispatchArea,
                null,
                null,
                StatisticsFilterPeriod::Quarter,
                2025,
                null,
                2,
                dispatchAreaId: $dispatchArea->getId(),
            ),
        );

        self::assertTrue($this->hasMenuKey($dispatchModel->scopePrimaryMenu, 'dispatch_area_group'));
        self::assertSame('ComparisonVmDispatch', $dispatchModel->headingScope);

        $cohortKey = new HospitalCohortKey(HospitalLocation::URBAN, HospitalTier::FULL);
        $cohortModel = $this->factory->create(
            new Request(query: [
                'comparison_scope' => 'hospital_cohort:'.$cohortKey->value(),
            ]),
            'app_stats_benchmarking',
            null,
            new StatisticsFilter(
                StatisticsFilterScope::HospitalCohort,
                null,
                $cohortKey,
                StatisticsFilterPeriod::Year,
                2025,
            ),
        );

        self::assertTrue($this->hasMenuKey($cohortModel->scopePrimaryMenu, 'cohort_group'));
        self::assertTrue($cohortModel->showScopeSecondaryPicker);
        self::assertNotEmpty($cohortModel->scopeSecondaryMenu);
    }

    public function testBuildsComparisonPeriodUrlsForMonthAndQuarter(): void
    {
        $model = $this->factory->create(
            new Request(query: [
                'scope' => 'public',
                'comparison_scope' => 'public',
                'comparison_period' => 'month',
                'comparison_year' => '2024',
                'comparison_month' => '5',
            ]),
            'app_stats_benchmarking',
            null,
            new StatisticsFilter(
                StatisticsFilterScope::Public,
                null,
                null,
                StatisticsFilterPeriod::Month,
                2024,
                5,
            ),
        );

        self::assertStringContainsString('comparison_period=month', $model->periodUrls['month']);
        self::assertStringContainsString('comparison_month=5', $model->periodUrls['month']);
        self::assertStringContainsString('comparison_period=quarter', $model->periodUrls['quarter']);
    }

    public function testAdminSeesHospitalsLabelInComparisonScopeMenu(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createMany(2);

        $model = $this->factory->create(
            new Request(query: ['comparison_scope' => 'my_hospitals']),
            'app_stats_benchmarking',
            $user,
            new StatisticsFilter(StatisticsFilterScope::MyHospitals, null, null, StatisticsFilterPeriod::All),
        );

        self::assertTrue($this->hasMenuKey($model->scopePrimaryMenu, 'my_hospitals_group'));
        self::assertSame('Hospitals', $this->menuLabel($model->scopePrimaryMenu, 'my_hospitals_group'));
    }

    /**
     * @param list<array{key: string, label: string, url: string, active: bool}> $menu
     */
    private function menuLabel(array $menu, string $key): string
    {
        foreach ($menu as $item) {
            if ($item['key'] === $key) {
                return $item['label'];
            }
        }

        throw new \RuntimeException(sprintf('Menu key "%s" not found.', $key));
    }

    /**
     * @param list<array{key: string, label: string, url: string, active: bool}> $menu
     */
    private function hasMenuKey(array $menu, string $key): bool
    {
        return array_any($menu, fn (array $item): bool => $item['key'] === $key);
    }
}
