<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\DefaultAnalysisViewFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigMapper;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
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

        self::assertSame(2, $state['schemaVersion']);
        self::assertSame($config->title, $restored->title);
        self::assertSame($config->dataSourceKey, $restored->dataSourceKey);
        self::assertSame($config->metricKeys, $restored->metricKeys);
        self::assertSame($config->visualMetricKey, $restored->visualMetricKey);
        self::assertSame($config->dimensionKey, $restored->dimensionKey);
        self::assertSame($config->timeGrain, $restored->timeGrain);
        self::assertSame($config->presentation->chartType, $restored->presentation->chartType);
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

    public function testStateArrayPreservesGrainAndChartType(): void
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
            ->withDimension(AnalysisDimensionKey::Time, AnalysisDimensionGrain::Year)
            ->withPresentation(new \App\Statistics\AnalysisExplorer\Domain\PresentationConfig(chartType: ChartPresentationType::Line));

        $state = $mapper->toStateArray($config);
        self::assertSame('year', $state['query']['grain']);
        self::assertSame('line', $state['presentation']['chartType']);

        $restored = $mapper->viewConfigFromState($state, null);
        self::assertSame(AnalysisDimensionGrain::Year, $restored->timeGrain);
        self::assertSame(ChartPresentationType::Line, $restored->presentation->chartType);
    }

    public function testStateRoundTripPreservesGenderTotalWithGroupedBar(): void
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
            ->withDimension(AnalysisDimensionKey::Gender, AnalysisDimensionGrain::Month)
            ->withPresentation(new \App\Statistics\AnalysisExplorer\Domain\PresentationConfig(chartType: ChartPresentationType::GroupedBar));

        $state = $mapper->toStateArray($config);
        $restored = $mapper->viewConfigFromState($state, null);

        self::assertSame(AnalysisDimensionKey::Gender, $restored->dimensionKey);
        self::assertSame(AnalysisDimensionGrain::Month, $restored->timeGrain);
        self::assertSame(ChartPresentationType::GroupedBar, $restored->presentation->chartType);
    }

    public function testLegacyGenderStateWithoutGrainNormalizesToTotal(): void
    {
        self::bootKernel();
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);

        $config = $mapper->viewConfigFromState([
            'schemaVersion' => 1,
            'dataSource' => 'allocations',
            'query' => [
                'scope' => ['group' => 'public', 'detail' => null],
                'period' => ['type' => 'all', 'year' => null, 'quarter' => null, 'month' => null],
                'metric' => 'allocation_count',
                'dimension' => 'gender',
                'grain' => null,
            ],
            'presentation' => ['mode' => 'chart', 'chartType' => 'bar'],
            'title' => 'Allocations by gender',
        ], null);

        self::assertSame(AnalysisDimensionKey::Gender, $config->dimensionKey);
        self::assertSame(AnalysisDimensionGrain::Total, $config->timeGrain);
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

        self::assertSame(AnalysisDimensionKey::Time, $config->dimensionKey);
        self::assertSame(AnalysisDimensionGrain::Year, $config->timeGrain);
        self::assertSame(ChartPresentationType::Line, $config->presentation->chartType);
    }
}
