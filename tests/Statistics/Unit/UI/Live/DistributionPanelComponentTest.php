<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\UI\Live;

use App\Statistics\Application\Filter\FilterRegistry;
use App\Statistics\Application\Mapping\AgeCohortValueMapper;
use App\Statistics\Application\Mapping\GenderValueMapper;
use App\Statistics\Application\Mapping\HospitalLocationValueMapper;
use App\Statistics\Application\Mapping\HospitalTypeValueMapper;
use App\Statistics\Application\Mapping\TriageValueMapper;
use App\Statistics\Application\Panel\Distribution\DistributionNumericMetricMerge;
use App\Statistics\Application\Panel\Distribution\DistributionPageConfigResolver;
use App\Statistics\Application\Panel\Distribution\DistributionSectionNavProvider;
use App\Statistics\Application\Panel\Distribution\DistributionTransformer;
use App\Statistics\Application\Panel\Distribution\Renderer;
use App\Statistics\Application\State\QueryStateResolver;
use App\Statistics\Infrastructure\Query\DistributionPanelQuery;
use App\Statistics\Infrastructure\Query\SqlFilterBuilder;
use App\Statistics\UI\Live\DistributionPanelComponent;
use App\Tests\Statistics\Fixtures\DistributionPanelFixtures;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DistributionPanelComponentTest extends TestCase
{
    public function testAllowedViewModesDependOnGrouping(): void
    {
        $component = $this->component();

        $component->groupedBy = 'none';
        self::assertSame(['absolute', 'percent_of_total'], $component->getAllowedViewModes());

        $component->groupedBy = 'tier';
        self::assertSame(['grouped', 'stacked', 'percent'], $component->getAllowedViewModes());
    }

    public function testPageConfigRequiresDistributionPageOptions(): void
    {
        $component = $this->bareComponent();
        $component->distributionPageOptions = [];

        $this->expectException(\LogicException::class);
        $component->pageConfig();
    }

    public function testPageConfigResolvesFromDistributionPageOptions(): void
    {
        $component = $this->bareComponent();
        $component->distributionPageOptions = DistributionPanelFixtures::sampleUrgencyPageOptions();

        self::assertSame('app_stats_distribution_urgency', $component->pageConfig()->routeName);
    }

    public function testUrlStateUsesNormalizedViewMode(): void
    {
        $component = $this->component();
        $component->groupedBy = 'none';
        $component->viewMode = 'stacked';
        $component->panelKey = 'urgency';
        $component->filterValues = [
            'date_range' => 'all_cases',
            'hospital_tier' => [],
            'hospital_location' => [],
        ];

        $state = $component->getUrlState();

        self::assertSame('absolute', $state['view']);
        self::assertSame('urgency', $state['panel']);
        self::assertSame('none', $state['grouped_by']);
        self::assertSame('all_cases', $state['f']['date_range']);
    }

    public function testGetUrlStateIncludesChartTypeAndBarBasis(): void
    {
        $component = $this->component();
        $component->chartType = 'boxplot';
        $component->barBasis = 'counts';

        $state = $component->getUrlState();

        self::assertSame('boxplot', $state['chart_type']);
        self::assertSame('counts', $state['bar_basis']);
    }

    public function testGetCrossSectionQueryParamsIncludesChartTypeAndBarBasis(): void
    {
        $component = $this->component();
        $component->chartType = 'bar';
        $component->barBasis = 'average';

        $params = $component->getCrossSectionQueryParams();

        self::assertSame('bar', $params['chart_type']);
        self::assertSame('average', $params['bar_basis']);
    }

    public function testGetRenderedDataBarCountsCallsDistributionQueryOnly(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([
                ['dimension_key' => '1', 'group_key' => null, 'value' => '5', 'distinct_hospitals' => '2'],
            ]);
        $connection->expects(self::never())->method('fetchAssociative');

        $component = $this->componentWithConnection($connection);
        $component->distributionPageOptions = DistributionPanelFixtures::sampleUrgencyPageOptions();
        $component->chartType = 'bar';
        $component->barBasis = 'counts';

        $data = $component->getRenderedData();

        self::assertSame('bar_counts', $data['tableMode']);
        self::assertSame('bar', $data['chart']['chart']['type']);
    }

    public function testGetRenderedDataBarAverageMergesHospitalParticipation(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([
                ['dimension_key' => '1', 'group_key' => null, 'value' => '4', 'distinct_hospitals' => '2'],
            ]);
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['allocations' => '8', 'distinct_hospitals' => '2']);

        $component = $this->componentWithConnection($connection);
        $component->distributionPageOptions = DistributionPanelFixtures::sampleUrgencyPageOptions();
        $component->chartType = 'bar';
        $component->barBasis = 'average';

        $data = $component->getRenderedData();

        self::assertSame('bar_average', $data['tableMode']);
        self::assertSame(2.0, $data['chart']['series'][0]['data'][0]);
    }

    public function testGetRenderedDataBoxplotLoadsNumericStats(): void
    {
        $dbNumericRow = [
            'dimension_key' => '1',
            'group_key' => null,
            'n' => '4',
            'mean_val' => '40',
            'min_val' => '30',
            'q1_val' => '35',
            'median_val' => '40',
            'q3_val' => '45',
            'max_val' => '50',
        ];

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))
            ->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls(
                [
                    ['dimension_key' => '1', 'group_key' => null, 'value' => '10', 'distinct_hospitals' => '2'],
                ],
                [$dbNumericRow],
            );
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn($dbNumericRow);

        $component = $this->componentWithConnection($connection);
        $component->distributionPageOptions = DistributionPanelFixtures::sampleUrgencyPageOptions();
        $component->chartType = 'boxplot';
        $component->barBasis = 'counts';

        $data = $component->getRenderedData();

        self::assertSame('boxplot', $data['tableMode']);
        self::assertSame('boxPlot', $data['chart']['chart']['type']);
        self::assertNotEmpty($data['chart']['series']);
    }

    public function testMountFallsBackToDefaultPanelForUnknownKey(): void
    {
        $opts = DistributionPanelFixtures::sampleUrgencyPageOptions();
        $c = $this->componentForRequest(Request::create('/statistics/distribution/urgency', Request::METHOD_GET, ['panel' => 'unknown']));
        $c->mount($opts);

        self::assertSame('urgency', $c->panelKey);
    }

    public function testMountForcesGroupedByNoneWhenPanelDisallowsGrouping(): void
    {
        $opts = DistributionPanelFixtures::sampleUrgencyPageOptions();
        $opts['panels'][0]['controls']['allow_group_by'] = false;

        $c = $this->componentForRequest(Request::create('/statistics/distribution/urgency', Request::METHOD_GET, ['grouped_by' => 'tier']));
        $c->mount($opts);

        self::assertSame('none', $c->groupedBy);
    }

    public function testMountReadsChartTypeAndNormalizesInvalidValue(): void
    {
        $opts = DistributionPanelFixtures::sampleUrgencyPageOptions();
        $c = $this->componentForRequest(Request::create('/statistics/distribution/urgency', Request::METHOD_GET, ['chart_type' => 'pie']));
        $c->mount($opts);

        self::assertSame('bar', $c->chartType);
    }

    public function testMountReadsBarBasisFromQuery(): void
    {
        $opts = DistributionPanelFixtures::sampleUrgencyPageOptions();
        $c = $this->componentForRequest(Request::create('/statistics/distribution/urgency', Request::METHOD_GET, ['bar_basis' => 'average']));
        $c->mount($opts);

        self::assertSame('average', $c->barBasis);
    }

    public function testShowChartTypeControlReflectsPolicy(): void
    {
        $c = $this->component();
        self::assertTrue($c->showChartTypeControl());
    }

    public function testShowBarBasisControlWhenBarChart(): void
    {
        $c = $this->component();
        $c->chartType = 'bar';
        self::assertTrue($c->showBarBasisControl());

        $c->chartType = 'boxplot';
        self::assertFalse($c->showBarBasisControl());
    }

    public function testUseYAxisPercentFormatterForPercentViewModes(): void
    {
        $c = $this->component();
        $c->chartType = 'bar';
        $c->barBasis = 'counts';
        $c->viewMode = 'percent';
        self::assertTrue($c->useYAxisPercentFormatter());

        $c->viewMode = 'absolute';
        self::assertFalse($c->useYAxisPercentFormatter());
    }

    public function testGetAllowedViewModesForBoxplotIsAbsoluteOnly(): void
    {
        $c = $this->component();
        $c->chartType = 'boxplot';
        self::assertSame(['absolute'], $c->getAllowedViewModes());
    }

    public function testGetAllowedViewModesForBarAverageWhenGrouped(): void
    {
        $c = $this->component();
        $c->chartType = 'bar';
        $c->barBasis = 'average';
        $c->groupedBy = 'tier';
        self::assertSame(['grouped'], $c->getAllowedViewModes());
    }

    public function testGetDistributionSectionNavMarksActiveRoute(): void
    {
        $nav = $this->component()->getDistributionSectionNav();
        self::assertNotEmpty($nav);
        $active = array_values(array_filter($nav, static fn (array $i): bool => true === $i['active']));
        self::assertCount(1, $active);
        self::assertSame('app_stats_distribution_urgency', $active[0]['route']);
    }

    public function testFilterHelpersExposeOptionsAndSelections(): void
    {
        $c = $this->component();
        self::assertNotEmpty($c->getHospitalTierFilterOptions());
        self::assertNotEmpty($c->getHospitalLocationFilterOptions());
        self::assertCount(3, $c->getActivePanelFilters());
        self::assertSame('all_cases', $c->getDateRangePreset());

        $c->filterValues['hospital_tier'] = 'not-an-array';
        self::assertSame([], $c->getHospitalTierSelection());
    }

    private function component(): DistributionPanelComponent
    {
        $c = $this->bareComponent();
        $c->distributionPageOptions = DistributionPanelFixtures::sampleUrgencyPageOptions();

        return $c;
    }

    private function bareComponent(): DistributionPanelComponent
    {
        $filterRegistry = new FilterRegistry();
        $query = new DistributionPanelQuery(
            $this->createMock(Connection::class),
            new SqlFilterBuilder($filterRegistry),
        );

        $stack = new RequestStack();
        $stack->push(Request::create('/statistics/distribution/urgency'));

        return $this->buildComponent($query, $stack);
    }

    private function componentForRequest(Request $request): DistributionPanelComponent
    {
        $filterRegistry = new FilterRegistry();
        $query = new DistributionPanelQuery(
            $this->createMock(Connection::class),
            new SqlFilterBuilder($filterRegistry),
        );
        $stack = new RequestStack();
        $stack->push($request);

        return $this->buildComponent($query, $stack);
    }

    private function componentWithConnection(Connection $connection): DistributionPanelComponent
    {
        $filterRegistry = new FilterRegistry();
        $query = new DistributionPanelQuery($connection, new SqlFilterBuilder($filterRegistry));
        $stack = new RequestStack();
        $stack->push(Request::create('/statistics/distribution/urgency'));

        return $this->buildComponent($query, $stack);
    }

    private function buildComponent(DistributionPanelQuery $query, RequestStack $stack): DistributionPanelComponent
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);

        $filterRegistry = new FilterRegistry();
        $resolver = new QueryStateResolver($filterRegistry);

        return new DistributionPanelComponent(
            new DistributionSectionNavProvider(),
            new DistributionPageConfigResolver(),
            $resolver,
            $query,
            new DistributionTransformer(),
            new DistributionNumericMetricMerge(),
            new Renderer($translator),
            new TriageValueMapper($translator),
            new GenderValueMapper($translator),
            new HospitalTypeValueMapper($translator),
            new HospitalLocationValueMapper($translator),
            new AgeCohortValueMapper($translator),
            $filterRegistry,
            $stack,
        );
    }
}
