<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\DefaultAnalysisViewFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigMapper;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerChartRowLimit;
use App\Statistics\AnalysisExplorer\Domain\Enum\TableLayout;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExplorerConfigMapperTest extends KernelTestCase
{
    public function testFilterToStateArrayRoundTrip(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );

        $config = $container->get(DefaultAnalysisViewFactory::class)->createDefault($filter);
        $mapper = $container->get(ExplorerConfigMapper::class);

        $state = $mapper->toStateArray($config);
        $restored = $mapper->viewConfigFromState($state, null);

        self::assertSame(3, $state['schemaVersion']);
        self::assertSame($config->title, $restored->title);
        self::assertSame($config->dataSourceKey, $restored->dataSourceKey);
        self::assertSame($config->metricKeys, $restored->metricKeys);
        self::assertSame($config->visualMetricKey, $restored->visualMetricKey);
        self::assertSame($config->rowAxis->dimensionKey, $restored->rowAxis->dimensionKey);
        self::assertSame($config->rowAxis->resolvedGrain(), $restored->rowAxis->resolvedGrain());
        self::assertSame($config->presentation->chartType, $restored->presentation->chartType);
        self::assertSame(ExplorerChartRowLimit::All, $restored->presentation->chartRowLimit);
        self::assertSame($config->statisticsFilter->scope, $restored->statisticsFilter->scope);
        self::assertSame($config->statisticsFilter->period, $restored->statisticsFilter->period);
    }

    public function testBuildViewConfigMergesFilterAndPreferences(): void
    {
        self::bootKernel();
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        $viewFactory = self::getContainer()->get(DefaultAnalysisViewFactory::class);

        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::Year,
            referenceYear: 2024,
        );
        $defaultConfig = $viewFactory->createDefault($filter);

        $config = $mapper->buildViewConfig(
            $mapper->filterToStateArray($filter),
            $mapper->viewPreferencesToStateArray(
                $defaultConfig->withPresentation(new \App\Statistics\AnalysisExplorer\Domain\PresentationConfig(chartType: ChartPresentationType::Line)),
            ),
            null,
        );

        self::assertSame(StatisticsFilterPeriod::Year, $config->statisticsFilter->period);
        self::assertSame(ChartPresentationType::Line, $config->presentation->chartType);
    }

    public function testStateArrayPreservesAxesAndChartType(): void
    {
        self::bootKernel();
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        $viewFactory = self::getContainer()->get(DefaultAnalysisViewFactory::class);

        $config = $viewFactory->createDefault(new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        ))
            ->withAxes(AnalysisAxisRef::time(AnalysisDimensionGrain::Year), null)
            ->withPresentation(new \App\Statistics\AnalysisExplorer\Domain\PresentationConfig(chartType: ChartPresentationType::Line));

        $state = $mapper->toStateArray($config);
        self::assertSame('year', $state['query']['rows']['grain']);
        self::assertSame('line', $state['presentation']['chartType']);

        $restored = $mapper->viewConfigFromState($state, null);
        self::assertSame(AnalysisDimensionGrain::Year, $restored->rowAxis->resolvedGrain());
        self::assertSame(ChartPresentationType::Line, $restored->presentation->chartType);
    }

    public function testStateRoundTripPreservesGenderOverTimeAxes(): void
    {
        self::bootKernel();
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        $viewFactory = self::getContainer()->get(DefaultAnalysisViewFactory::class);

        $config = $viewFactory->createDefault(new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        ))
            ->withAxes(
                AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
                AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            )
            ->withPresentation(new \App\Statistics\AnalysisExplorer\Domain\PresentationConfig(
                chartType: ChartPresentationType::GroupedBar,
                tableLayout: TableLayout::Matrix,
            ));

        $state = $mapper->toStateArray($config);
        $restored = $mapper->viewConfigFromState($state, null);

        self::assertSame(AnalysisDimensionKey::Time, $restored->rowAxis->dimensionKey);
        self::assertSame(AnalysisDimensionGrain::Month, $restored->rowAxis->resolvedGrain());
        self::assertSame(AnalysisDimensionKey::Gender, $restored->columnAxis?->dimensionKey);
        self::assertSame(ChartPresentationType::GroupedBar, $restored->presentation->chartType);
    }

    public function testLegacyGenderStateWithoutGrainNormalizesToTotal(): void
    {
        self::bootKernel();
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);

        $config = $mapper->viewConfigFromState([
            'schemaVersion' => 2,
            'dataSource' => 'allocations',
            'query' => [
                'scope' => ['group' => 'public', 'detail' => null],
                'period' => ['type' => 'all', 'year' => null, 'quarter' => null, 'month' => null],
                'metrics' => ['allocation_count'],
                'visualMetric' => 'allocation_count',
                'dimension' => 'gender',
                'grain' => null,
            ],
            'presentation' => ['mode' => 'chart', 'chartType' => 'bar'],
            'title' => 'Allocations by gender',
        ], null);

        self::assertSame(AnalysisDimensionKey::Gender, $config->rowAxis->dimensionKey);
        self::assertSame(AnalysisDimensionGrain::Total, $config->rowAxis->resolvedGrain());
        self::assertNull($config->columnAxis);
    }

    public function testLegacySingularMetricUpgradesToMetricsArray(): void
    {
        self::bootKernel();
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);

        $config = $mapper->viewConfigFromState([
            'schemaVersion' => 2,
            'dataSource' => 'allocations',
            'query' => [
                'scope' => ['group' => 'public', 'detail' => null],
                'period' => ['type' => 'all', 'year' => null, 'quarter' => null, 'month' => null],
                'metric' => 'allocation_count',
                'rows' => ['dimension' => 'time', 'grain' => 'month'],
            ],
            'presentation' => ['mode' => 'chart', 'chartType' => 'bar'],
            'title' => 'Allocations over time',
        ], null);

        self::assertSame([AnalysisMetricKey::AllocationCount], $config->metricKeys);
        self::assertSame(AnalysisMetricKey::AllocationCount, $config->visualMetricKey);
    }

    public function testLegacyFlatStateIsUpgraded(): void
    {
        self::bootKernel();
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);

        $config = $mapper->viewConfigFromState([
            'scopeGroup' => 'public',
            'period' => 'all',
            'dimensionGrain' => 'year',
            'chartType' => 'line',
            'title' => 'Allocations over time',
        ], null);

        self::assertSame(AnalysisDimensionKey::Time, $config->rowAxis->dimensionKey);
        self::assertSame(AnalysisDimensionGrain::Year, $config->rowAxis->resolvedGrain());
        self::assertSame(ChartPresentationType::Line, $config->presentation->chartType);
    }

    public function testV2GenderMonthUpgradesToTimeRowsAndGenderColumns(): void
    {
        self::bootKernel();
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);

        $config = $mapper->viewConfigFromState([
            'schemaVersion' => 2,
            'dataSource' => 'allocations',
            'query' => [
                'scope' => ['group' => 'public', 'detail' => null],
                'period' => ['type' => 'all', 'year' => null, 'quarter' => null, 'month' => null],
                'metrics' => ['allocation_count'],
                'visualMetric' => 'allocation_count',
                'dimension' => 'gender',
                'grain' => 'month',
            ],
            'presentation' => ['mode' => 'chart', 'chartType' => 'grouped_bar'],
            'title' => 'Gender over time',
        ], null);

        self::assertSame(AnalysisDimensionKey::Time, $config->rowAxis->dimensionKey);
        self::assertSame(AnalysisDimensionGrain::Month, $config->rowAxis->resolvedGrain());
        self::assertSame(AnalysisDimensionKey::Gender, $config->columnAxis?->dimensionKey);
    }

    public function testChartRowLimitRoundTripAndDefault(): void
    {
        self::bootKernel();
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        $viewFactory = self::getContainer()->get(DefaultAnalysisViewFactory::class);

        $config = $viewFactory->createDefault(new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        ))
            ->withPresentation(new \App\Statistics\AnalysisExplorer\Domain\PresentationConfig(
                chartType: ChartPresentationType::Bar,
                chartRowLimit: ExplorerChartRowLimit::Top10,
            ));

        $state = $mapper->toStateArray($config);
        self::assertSame('10', $state['presentation']['chartRowLimit']);

        $restored = $mapper->viewConfigFromState($state, null);
        self::assertSame(ExplorerChartRowLimit::Top10, $restored->presentation->chartRowLimit);

        $legacyState = $mapper->viewConfigFromState([
            'schemaVersion' => 3,
            'dataSource' => 'allocations',
            'query' => [
                'scope' => ['group' => 'public', 'detail' => null],
                'period' => ['type' => 'all', 'year' => null, 'quarter' => null, 'month' => null],
                'metrics' => ['allocation_count'],
                'visualMetric' => 'allocation_count',
                'rows' => ['dimension' => 'time', 'grain' => 'month'],
            ],
            'presentation' => ['mode' => 'chart', 'chartType' => 'bar'],
            'title' => 'Allocations over time',
        ], null);

        self::assertSame(ExplorerChartRowLimit::All, $legacyState->presentation->chartRowLimit);
    }

    public function testBuildViewConfigFromLegacyDistributionMetricResolvesBoxPlot(): void
    {
        self::bootKernel();
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);

        $filterState = [
            'scope' => ['group' => 'public', 'detail' => null],
            'period' => ['type' => 'all', 'year' => null, 'quarter' => null, 'month' => null],
        ];

        $config = $mapper->buildViewConfig($filterState, [
            'dataSource' => AnalysisDataSourceKey::Hospitals->value,
            'dimension' => 'hospital_tier',
            'metric' => AnalysisMetricKey::BedsDistribution->value,
            'chartType' => ChartPresentationType::BoxPlot->value,
            'title' => 'Beds distribution by tier',
        ], null);

        self::assertSame([AnalysisMetricKey::BedsDistribution], $config->metricKeys);
        self::assertSame(AnalysisMetricKey::BedsDistribution, $config->visualMetricKey);
        self::assertSame(ChartPresentationType::BoxPlot, $config->presentation->chartType);
    }

    public function testBuildViewConfigUsesDataSourceDefaultWhenNoMetric(): void
    {
        self::bootKernel();
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);

        $filterState = [
            'scope' => ['group' => 'public', 'detail' => null],
            'period' => ['type' => 'all', 'year' => null, 'quarter' => null, 'month' => null],
        ];

        $config = $mapper->buildViewConfig($filterState, [
            'dataSource' => AnalysisDataSourceKey::Hospitals->value,
            'dimension' => 'hospital_tier',
            'title' => 'Hospitals by tier',
        ], null);

        self::assertSame([AnalysisMetricKey::HospitalCount], $config->metricKeys);
        self::assertSame(AnalysisMetricKey::HospitalCount, $config->visualMetricKey);
    }

    public function testMergeChartRowLimitIntoState(): void
    {
        self::bootKernel();
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        $viewFactory = self::getContainer()->get(DefaultAnalysisViewFactory::class);
        $state = $mapper->toStateArray($viewFactory->createDefault(new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        )));

        $merged = $mapper->mergeChartRowLimitIntoState($state, ExplorerChartRowLimit::Top5);
        self::assertSame('5', $merged['presentation']['chartRowLimit']);
    }
}
