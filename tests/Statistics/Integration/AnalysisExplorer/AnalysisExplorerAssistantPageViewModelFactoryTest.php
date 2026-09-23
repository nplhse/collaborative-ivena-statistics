<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerAssistantConfigResolver;
use App\Statistics\AnalysisExplorer\Application\ExplorerAssistantFilters;
use App\Statistics\AnalysisExplorer\Application\ExplorerAssistantQuery;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerAssistantGoal;
use App\Statistics\AnalysisExplorer\UI\Http\Controller\AnalysisExplorerAssistantPageViewModelFactory;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\UI\Form\Data\StatisticsScopePeriodFormData;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class AnalysisExplorerAssistantPageViewModelFactoryTest extends KernelTestCase
{
    private AnalysisExplorerAssistantPageViewModelFactory $factory;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->factory = self::getContainer()->get(AnalysisExplorerAssistantPageViewModelFactory::class);
    }

    public function testScopeQueryCopiesTheStatisticsParametersThatArePresent(): void
    {
        $query = $this->factory->scopeQuery(Request::create('/statistics/analysis/assistant', 'GET', [
            StatisticsQueryKeys::SCOPE => 'public',
            StatisticsQueryKeys::HOSPITAL => '9',
            StatisticsQueryKeys::COHORT => 'urban_basic',
            StatisticsQueryKeys::STATE => '3',
            StatisticsQueryKeys::DISPATCH_AREA => '4',
            StatisticsQueryKeys::PERIOD => 'quarter',
            StatisticsQueryKeys::YEAR => '2024',
            StatisticsQueryKeys::MONTH => '5',
            StatisticsQueryKeys::QUARTER => '2',
        ]));

        self::assertSame([
            StatisticsQueryKeys::SCOPE => 'public',
            StatisticsQueryKeys::HOSPITAL => '9',
            StatisticsQueryKeys::COHORT => 'urban_basic',
            StatisticsQueryKeys::STATE => '3',
            StatisticsQueryKeys::DISPATCH_AREA => '4',
            StatisticsQueryKeys::PERIOD => 'quarter',
            StatisticsQueryKeys::YEAR => '2024',
            StatisticsQueryKeys::MONTH => '5',
            StatisticsQueryKeys::QUARTER => '2',
        ], $query);
    }

    public function testScopeQueryFromSideKeepsDetailAndCalendarFields(): void
    {
        $month = $this->factory->scopeQueryFromSide(new StatisticsScopePeriodFormData(
            scopeGroup: 'state',
            scopeDetail: '12',
            period: StatisticsFilterPeriod::Month->value,
            periodYear: 2024,
            periodMonth: 6,
        ));
        self::assertSame('state', $month[StatisticsQueryKeys::SCOPE]);
        self::assertSame('12', $month[StatisticsQueryKeys::STATE]);
        self::assertSame('2024', $month[StatisticsQueryKeys::YEAR]);
        self::assertSame('6', $month[StatisticsQueryKeys::MONTH]);

        $quarter = $this->factory->scopeQueryFromSide(new StatisticsScopePeriodFormData(
            scopeGroup: 'dispatch_area',
            scopeDetail: '4',
            period: StatisticsFilterPeriod::Quarter->value,
            periodYear: 2023,
            periodQuarter: 2,
        ));
        self::assertSame('4', $quarter[StatisticsQueryKeys::DISPATCH_AREA]);
        self::assertSame('2', $quarter[StatisticsQueryKeys::QUARTER]);

        $hospital = $this->factory->scopeQueryFromSide(new StatisticsScopePeriodFormData(
            scopeGroup: 'my_hospitals',
            scopeDetail: '15',
            period: StatisticsFilterPeriod::Year->value,
            periodYear: 2022,
        ));
        self::assertSame('15', $hospital[StatisticsQueryKeys::HOSPITAL]);
        self::assertSame('2022', $hospital[StatisticsQueryKeys::YEAR]);

        $cohort = $this->factory->scopeQueryFromSide(new StatisticsScopePeriodFormData(
            scopeGroup: 'hospital_cohort',
            scopeDetail: 'urban_basic',
            period: StatisticsFilterPeriod::All->value,
        ));
        self::assertSame('urban_basic', $cohort[StatisticsQueryKeys::COHORT]);
        self::assertArrayNotHasKey(StatisticsQueryKeys::YEAR, $cohort);
    }

    public function testSummaryDescribesGrainsChartsAndEveryAllocationFilter(): void
    {
        $draft = new \App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerAssistantDraft();
        $draft->currentStep = 'summary';
        $draft->goal = ExplorerAssistantGoal::Matrix;
        $draft->dataSource = AnalysisDataSourceKey::Allocations;
        $draft->row = AnalysisDimensionKey::Weekday;
        $draft->column = AnalysisDimensionKey::Time;
        $draft->columnGrain = AnalysisDimensionGrain::Quarter;
        $draft->metric = AnalysisMetricKey::TransportTimeDistribution;
        $draft->filterDepartmentId = 1;
        $draft->filterSpecialityId = 2;
        $draft->filterUrgency = 3;
        $draft->filterTransportType = 4;
        $draft->filterGender = 2;
        $draft->filterAgeGroup = 'under_18';
        $draft->filterResus = true;
        $draft->filterCpr = false;
        $draft->filterVentilation = true;
        $draft->filterAssignmentId = 5;
        $draft->filterIndicationId = 6;
        $draft->filterSecondaryIndicationId = 7;
        $draft->filterIndicationGroupId = 8;
        $draft->scopePeriod = new StatisticsScopePeriodFormData(period: StatisticsFilterPeriod::All->value);

        $page = $this->factory->create($this->filter(), $draft);

        self::assertNotNull($page->summaryPresentation);
        $filters = $this->line($page->summaryLines, 'filters');
        self::assertStringContainsString('Under 18', $filters);
        self::assertStringContainsString('Requires Resus', $filters);
        self::assertStringContainsString(':', $filters);
        $column = '';
        foreach ($page->summaryLines as $line) {
            if (str_contains($line->value, '(')) {
                $column = $line->value;
            }
        }
        self::assertNotSame('', $column);
    }

    public function testSummaryWithoutAResolvableAnalysisNamesTheMissingChart(): void
    {
        $draft = new \App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerAssistantDraft();
        $draft->currentStep = 'summary';
        $draft->goal = ExplorerAssistantGoal::Distribution;
        $draft->row = null;
        $draft->metric = AnalysisMetricKey::AllocationCount;

        $page = $this->factory->create($this->filter(), $draft);

        self::assertNull($page->summaryTitle);
        self::assertNull($page->summaryPresentation);
        self::assertNotSame('', $this->line($page->summaryLines, 'questions'));
    }

    public function testResolverDropsAGuideTheNormalizerCanNoLongerMatch(): void
    {
        $resolver = self::getContainer()->get(ExplorerAssistantConfigResolver::class);
        $filter = $this->filter();

        self::assertNull($resolver->resolve(new ExplorerAssistantQuery(
            ExplorerAssistantGoal::Distribution,
            null,
            null,
            null,
            null,
            AnalysisMetricKey::AllocationCount,
            AnalysisDataSourceKey::Allocations,
            ExplorerAssistantFilters::none(),
            false,
        ), $filter));

        self::assertNull($resolver->resolve(new ExplorerAssistantQuery(
            ExplorerAssistantGoal::Distribution,
            AnalysisDimensionKey::HospitalTier,
            null,
            null,
            null,
            AnalysisMetricKey::AllocationCount,
            AnalysisDataSourceKey::Allocations,
            ExplorerAssistantFilters::none(),
            false,
        ), $filter));

        self::assertNull($resolver->resolve(new ExplorerAssistantQuery(
            ExplorerAssistantGoal::Matrix,
            AnalysisDimensionKey::Weekday,
            AnalysisDimensionKey::HospitalTier,
            null,
            null,
            AnalysisMetricKey::AllocationCount,
            AnalysisDataSourceKey::Allocations,
            ExplorerAssistantFilters::none(),
            false,
        ), $filter));

        self::assertNull($resolver->resolve(new ExplorerAssistantQuery(
            ExplorerAssistantGoal::Distribution,
            AnalysisDimensionKey::HospitalTier,
            null,
            null,
            null,
            AnalysisMetricKey::HospitalCount,
            AnalysisDataSourceKey::Hospitals,
            new ExplorerAssistantFilters(departmentId: 1),
            false,
        ), $filter));
    }

    private function filter(): StatisticsFilter
    {
        return new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );
    }

    /**
     * @param list<\App\Statistics\AnalysisExplorer\UI\Http\Controller\AnalysisExplorerAssistantSummaryLineViewModel> $lines
     */
    private function line(array $lines, string $step): string
    {
        foreach ($lines as $line) {
            if ($line->editStep === $step) {
                return $line->value;
            }
        }

        self::fail('Missing summary line for '.$step);
    }
}
