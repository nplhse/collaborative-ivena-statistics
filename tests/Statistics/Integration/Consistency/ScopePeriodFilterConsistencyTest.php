<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Consistency;

use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\State;
use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalPermission;
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
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\AnalysisExplorer\Application\AnalysisExecution;
use App\Statistics\AnalysisExplorer\Application\DefaultAnalysisViewFactory;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\Application\ComparisonScopeResolver;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\DTO\StatisticsDrawerFilter;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\IndicationDashboard\IndicationDashboardService;
use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\Application\Insights\InsightDimensionRegistry;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsDrawerFilterFactory;
use App\Statistics\Application\StatisticsFilterFactory;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\Application\SummarizedReport\Monthly\MonthlyReportBuilder;
use App\Statistics\Application\TimeSeries\TimeSeriesGrainResolver;
use App\Statistics\Application\TopList\TopListDefinitionRegistry;
use App\Statistics\Application\TopList\TopListLimit;
use App\Statistics\Application\TopList\TopListResultLoader;
use App\Statistics\Benchmarking\Application\BenchmarkCriteriaFactory;
use App\Statistics\Benchmarking\Application\BenchmarkReportService;
use App\Statistics\CaseFlow\Application\CaseFlowCriteriaFactory;
use App\Statistics\CaseFlow\Application\CaseFlowDashboardService;
use App\Statistics\ClosedDepartmentAssignments\Application\ClosedDepartmentAssignmentsCriteriaFactory;
use App\Statistics\ClosedDepartmentAssignments\Application\ClosedDepartmentAssignmentsService;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisFilter;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisFilterOperator;
use App\Statistics\Infrastructure\Query\Overview\GetOverviewDashboardMetricsQuery;
use App\Statistics\Infrastructure\Query\Overview\OverviewQueryCriteria;
use App\Statistics\UI\Http\Controller\StatisticsFilterInputFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\Tests\Support\MaterializedView\RefreshesStatisticsMaterializedViewsTrait;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Component\HttpFoundation\Request;

/**
 * The same Scope × Period × Filter gives the same allocation count on every statistics surface (#579).
 *
 * Every surface gets its criteria the way its controller builds them: the URL query goes through
 * StatisticsFilterInputFactory, StatisticsFilterFactory and StatisticsDrawerFilterFactory. The Explorer has no
 * drawer; it gets the same restriction as one of its own filters.
 *
 * Fixture:
 * - State S1 with dispatch areas D1, D2; state S2 with D3.
 * - H1–H4 in S1, all urban × full (cohort urban_full); catchment H1, H2 = D1, H3, H4 = D2. H5 in S2, rural × basic.
 *   The user owns H1.
 * - H1, Q2 2025, 10 allocations:
 *   - April (rows 1–4): SK1, SK1, SK1, SK2; ages 10, 15, 30, 40; closed department on rows 1 and 4; origin D1.
 *   - May (rows 5–7): SK2 ×3; ages 50, 60, 70; origin D1.
 *   - June (rows 8–10): SK3 ×3; ages 75, 85, 45; origin D2.
 *   - Indication B on rows 4–6, C on the others.
 * - H1 outside Q2: 2025-03-31 23:59 and 2025-07-01 00:00 (the exclusive end).
 * - H2, H3, H4: 4 allocations each, two in April (SK1, SK2) and two in May (SK2, SK3), ages 30–60, indication C.
 *   Origins H2 D1, D1, D2, D1; H3 D2, D2, D1, D2; H4 D2, D1, D2, D2. H5: 4 allocations from D3.
 * - No allocations in August 2025.
 *
 * Counts by hand, Q2 2025:
 * - H1 10; S1 = cohort = 10 + 3 × 4 = 22; origin D1 = 7 (H1) + 3 (H2) + 1 (H3) + 1 (H4) = 12; all time 28.
 * - April: H1 4; S1 = cohort 4 + 3 × 2 = 10; origin D1 4 + 2 + 0 + 1 = 7; public 12.
 * - H1 with SK1: 3 (April 3, one of them closed). H1 adults (18 and over): 8 (April 2, one of them closed).
 * - Closed departments: rows 1 and 4 of H1, both origin D1.
 */
final class ScopePeriodFilterConsistencyTest extends DatabaseKernelTestCase
{
    use RefreshesStatisticsMaterializedViewsTrait;

    private User $owner;

    /** @var array<string, int> */
    private array $ids = [];

    public function testEverySurfaceCountsTheSameAllocations(): void
    {
        self::bootKernel();
        $this->seed();
        $this->refreshStatisticsMaterializedViews();

        $q2 = ['period' => 'quarter', 'year' => '2025', 'quarter' => '2'];
        $h1 = ['scope' => 'hospital', 'hospital' => (string) $this->ids['H1']];
        $same = static fn (int $total, int $april, int $closed, int $indicationB): array => [
            'overview' => $total,
            'explorer' => $total,
            'top_diagnoses' => $total,
            'benchmarking' => $total,
            'insights_indication_b' => $indicationB,
            'closed_departments' => [$closed, $total],
            'monthly_april' => [$april, $closed],
            'case_flow' => $total,
        ];

        $this->assertSurfaces('hospital H1, Q2', $h1 + $q2, $same(10, 4, 2, 3));
        $this->assertSurfaces('state S1, Q2', ['scope' => 'state', 'state' => (string) $this->ids['S1']] + $q2, $same(22, 10, 2, 3));
        $this->assertSurfaces('cohort urban_full, Q2', ['scope' => 'hospital_cohort', 'cohort' => 'urban_full'] + $q2, $same(22, 10, 2, 3));
        $this->assertSurfaces('public, all time', ['scope' => 'public', 'period' => 'all'], $same(28, 12, 2, 3));

        // Named exception (statistics-filter-and-scope.md, case-flow.md): Case Flow counts a dispatch area as
        // origin OR catchment, 12 + the 4 allocations to H1 and H2 from D2 = 16.
        $this->assertSurfaces(
            'dispatch area D1, Q2',
            ['scope' => 'dispatch_area', 'dispatch_area' => (string) $this->ids['D1']] + $q2,
            ['case_flow' => 16] + $same(12, 7, 2, 3),
        );

        // Surfaces with the drawer apply it to numerator and denominator.
        $this->assertSurfaces('hospital H1, Q2, SK1', $h1 + $q2 + ['urgency' => '1'], [
            'explorer' => 3,
            'top_diagnoses' => 3,
            'monthly_april' => [3, 1],
            'case_flow' => 3,
        ]);
        $this->assertSurfaces('hospital H1, Q2, adults', $h1 + $q2 + ['age_group' => 'adults'], [
            'explorer' => 8,
            'top_diagnoses' => 8,
            'monthly_april' => [2, 1],
            'case_flow' => 8,
        ]);

        // An empty month counts nothing anywhere; a share without denominator has no value.
        $august = $this->measure($h1 + ['period' => 'month', 'year' => '2025', 'month' => '8']);
        self::assertSame(0, $august['overview']);
        self::assertSame('no_data', $august['explorer_empty_reason']);
        self::assertSame(0, $august['top_diagnoses']);
        self::assertSame([0, 0], $august['closed_departments']);
        self::assertNull($august['closed_departments_share']);
        self::assertSame(0, $august['case_flow']);
        self::assertNull($august['case_flow_regional_share']);
    }

    /**
     * @param array<string, string>              $query
     * @param array<string, int|array{int, int}> $expected
     */
    private function assertSurfaces(string $case, array $query, array $expected): void
    {
        $actual = array_intersect_key($this->measure($query), $expected);
        ksort($actual);
        ksort($expected);

        self::assertSame($expected, $actual, $case);
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function measure(array $query): array
    {
        $c = self::getContainer();
        $request = Request::create('/statistics', 'GET', $query);
        $filter = $c->get(StatisticsFilterFactory::class)->createFromInput(
            $c->get(StatisticsFilterInputFactory::class)->fromQuery($request->query, $this->owner),
            $this->owner,
        );
        $drawer = $c->get(StatisticsDrawerFilterFactory::class)->fromRequest($request);
        $context = $c->get(StatisticsContextFactory::class)->create($this->owner, $filter);
        $scope = $c->get(StatisticsScopeResolver::class)->resolveCriteria($context);
        $bounds = StatisticsPeriodResolver::resolve($filter);

        $overview = $c->get(GetOverviewDashboardMetricsQuery::class)(
            OverviewQueryCriteria::fromPeriodBounds($bounds, $scope->hospitalIds, $scope->dispatchAreaId),
        );

        $explorer = $c->get(AnalysisExecution::class)->execute($this->explorerConfig($filter, $drawer), $this->owner);

        $topDiagnoses = $c->get(TopListDefinitionRegistry::class)->get('top_diagnoses');
        self::assertNotNull($topDiagnoses);
        $topList = $c->get(TopListResultLoader::class)
            ->load($request, $this->owner, $filter, $topDiagnoses, TopListLimit::all(), false);

        $comparisonFilter = $c->get(ComparisonScopeResolver::class)
            ->resolve($request, $this->owner, $filter, HospitalPermission::Benchmarking);
        $benchmark = $c->get(BenchmarkReportService::class)->build($c->get(BenchmarkCriteriaFactory::class)->create(
            $c->get(StatisticsContextFactory::class)->create($this->owner, $filter, comparisonFilter: $comparisonFilter),
            $comparisonFilter,
        ));

        $insightProvider = $c->get(InsightDimensionRegistry::class)->get(InsightDimensionKey::Indications);
        $subject = $insightProvider->resolve($this->ids['indicationB']);
        self::assertNotNull($subject);
        $insight = $c->get(IndicationDashboardService::class)->buildForInsight(
            $subject,
            $scope,
            $bounds,
            TimeSeriesGrainResolver::resolve($filter->period),
            $insightProvider->disabledInsightIds(),
        );

        $closed = $c->get(ClosedDepartmentAssignmentsService::class)->buildSummary(
            $c->get(ClosedDepartmentAssignmentsCriteriaFactory::class)->create($this->owner, $filter, false),
        )->kpis;

        $monthly = $c->get(MonthlyReportBuilder::class)->build(
            $c->get(StatisticsContextFactory::class)->create($this->owner, $filter, drawerFilter: $drawer),
            'en',
            null,
            2025,
            4,
        );

        $caseFlow = $c->get(CaseFlowDashboardService::class)
            ->build($c->get(CaseFlowCriteriaFactory::class)->create($this->owner, $filter, $request))
            ->kpis;

        return [
            'overview' => $overview->scopedTotal,
            'explorer' => (int) $explorer->result->totalFor(AnalysisMetricKey::AllocationCount),
            'explorer_empty_reason' => $explorer->emptyReason,
            'top_diagnoses' => $topList->rankingA->totalAllocations,
            'benchmarking' => $benchmark->header->primaryTotal,
            'insights_indication_b' => $insight?->header->caseCount,
            'closed_departments' => [$closed->closedCount, $closed->totalCount],
            'closed_departments_share' => $closed->sharePercent,
            'monthly_april' => [$monthly->allocationCount, $monthly->closedDepartment->closedCount],
            'case_flow' => $caseFlow->totalCases,
            'case_flow_regional_share' => $caseFlow->regionalSharePercent,
        ];
    }

    private function explorerConfig(StatisticsFilter $filter, StatisticsDrawerFilter $drawer): AnalysisViewConfig
    {
        $filters = [];
        if (null !== $drawer->urgency) {
            $filters[] = new AnalysisFilter('urgency', AnalysisFilterOperator::Equals, $drawer->urgency);
        }
        if (null !== $drawer->ageGroup) {
            $filters[] = new AnalysisFilter('age_group', AnalysisFilterOperator::Equals, $drawer->ageGroup);
        }

        return self::getContainer()->get(DefaultAnalysisViewFactory::class)->createDefault($filter)->withFilters($filters);
    }

    private function seed(): void
    {
        $this->owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $other = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $s1 = StateFactory::createOne(['name' => 'State S1']);
        $s2 = StateFactory::createOne(['name' => 'State S2']);
        $d1 = DispatchAreaFactory::createOne(['name' => 'Dispatch D1', 'state' => $s1]);
        $d2 = DispatchAreaFactory::createOne(['name' => 'Dispatch D2', 'state' => $s1]);
        $d3 = DispatchAreaFactory::createOne(['name' => 'Dispatch D3', 'state' => $s2]);
        $hospital = static fn (string $name, User $owner, State $state, DispatchArea $area, HospitalLocation $location, HospitalTier $tier): Hospital => HospitalFactory::createOne([
            'name' => $name, 'owner' => $owner, 'state' => $state, 'dispatchArea' => $area, 'location' => $location, 'tier' => $tier,
        ]);
        $h1 = $hospital('Hospital H1', $this->owner, $s1, $d1, HospitalLocation::URBAN, HospitalTier::FULL);
        $h2 = $hospital('Hospital H2', $other, $s1, $d1, HospitalLocation::URBAN, HospitalTier::FULL);
        $h3 = $hospital('Hospital H3', $other, $s1, $d2, HospitalLocation::URBAN, HospitalTier::FULL);
        $h4 = $hospital('Hospital H4', $other, $s1, $d2, HospitalLocation::URBAN, HospitalTier::FULL);
        $h5 = $hospital('Hospital H5', $other, $s2, $d3, HospitalLocation::RURAL, HospitalTier::BASIC);
        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        IndicationRawFactory::createOne();
        $indicationB = IndicationNormalizedFactory::createOne(['name' => 'Indication B', 'code' => 903]);
        $indicationC = IndicationNormalizedFactory::createOne(['name' => 'Indication C', 'code' => 904]);

        $this->ids = [
            'S1' => (int) $s1->getId(),
            'D1' => (int) $d1->getId(),
            'H1' => (int) $h1->getId(),
            'indicationB' => (int) $indicationB->getId(),
        ];

        $imports = [];
        foreach ([$h1, $h2, $h3, $h4, $h5] as $h) {
            $imports[] = ImportFactory::createOne(['hospital' => $h, 'createdBy' => $this->owner]);
        }
        [$import1, $import2, $import3, $import4, $import5] = $imports;

        // [created at, urgency, age, closed department, origin, indication]
        $h1Rows = [
            ['2025-04-03 09:00', 1, 10, true, $d1, $indicationC],
            ['2025-04-10 14:00', 1, 15, false, $d1, $indicationC],
            ['2025-04-17 20:00', 1, 30, false, $d1, $indicationC],
            ['2025-04-24 03:00', 2, 40, true, $d1, $indicationB],
            ['2025-05-05 10:00', 2, 50, false, $d1, $indicationB],
            ['2025-05-15 11:00', 2, 60, false, $d1, $indicationB],
            ['2025-05-25 16:00', 2, 70, false, $d1, $indicationC],
            ['2025-06-05 08:00', 3, 75, false, $d2, $indicationC],
            ['2025-06-15 13:00', 3, 85, false, $d2, $indicationC],
            ['2025-06-25 18:00', 3, 45, false, $d2, $indicationC],
            ['2025-03-31 23:59', 1, 40, false, $d1, $indicationC],
            ['2025-07-01 00:00', 1, 40, false, $d1, $indicationC],
        ];
        foreach ($h1Rows as $row) {
            $this->allocation($import1, $h1, $s1, ...$row);
        }

        $fourRows = static fn (DispatchArea ...$origins): array => array_map(
            static fn (int $i, DispatchArea $origin): array => [
                sprintf('2025-%02d-1%d 10:00', 4 + intdiv($i, 2), $i), [1, 2, 2, 3][$i], 30 + 10 * $i, false, $origin, $indicationC,
            ],
            array_keys($origins),
            $origins,
        );
        $others = [
            [$import2, $h2, $s1, $fourRows($d1, $d1, $d2, $d1)],
            [$import3, $h3, $s1, $fourRows($d2, $d2, $d1, $d2)],
            [$import4, $h4, $s1, $fourRows($d2, $d1, $d2, $d2)],
            [$import5, $h5, $s2, $fourRows($d3, $d3, $d3, $d3)],
        ];
        foreach ($others as [$import, $h, $state, $rows]) {
            foreach ($rows as $row) {
                $this->allocation($import, $h, $state, ...$row);
            }
        }

        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        foreach ($imports as $import) {
            $rebuilder->rebuildForImport((int) $import->getId());
        }
    }

    private function allocation(
        Import $import,
        Hospital $hospital,
        State $state,
        string $createdAt,
        int $urgency,
        int $age,
        bool $closed,
        DispatchArea $origin,
        IndicationNormalized $indication,
    ): void {
        $created = new \DateTimeImmutable($createdAt);
        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $origin,
            'urgency' => AllocationUrgency::from($urgency),
            'createdAt' => $created,
            'arrivalAt' => $created->modify('+15 minutes'),
            'age' => $age,
            'gender' => AllocationGender::FEMALE,
            'departmentWasClosed' => $closed,
            'indicationNormalized' => $indication,
            'infection' => null,
            'occasion' => null,
            'assessment' => null,
            'secondaryTransport' => null,
            'transportType' => AllocationTransportType::GROUND,
            'isCPR' => false,
            'isPregnant' => false,
            'isShock' => false,
            'isVentilated' => false,
            'isWithPhysician' => false,
            'isWorkAccident' => false,
            'requiresCathlab' => false,
            'requiresResus' => false,
        ]);
    }
}
