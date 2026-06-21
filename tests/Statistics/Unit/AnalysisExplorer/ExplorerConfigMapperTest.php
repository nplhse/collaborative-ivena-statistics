<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\DefaultAnalysisViewFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigMapper;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
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

        self::assertSame($config->title, $restored->title);
        self::assertSame($config->dataSourceKey, $restored->dataSourceKey);
        self::assertSame($config->metricKey, $restored->metricKey);
        self::assertSame($config->dimensionGrain, $restored->dimensionGrain);
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
            ->withDimensionGrain(AnalysisDimensionGrain::Year)
            ->withPresentation(new \App\Statistics\AnalysisExplorer\Domain\PresentationConfig(chartType: ChartPresentationType::Line));

        $state = $mapper->toStateArray($config);
        self::assertSame('year', $state['dimensionGrain']);
        self::assertSame('line', $state['chartType']);

        $restored = $mapper->viewConfigFromState($state, null);
        self::assertSame(AnalysisDimensionGrain::Year, $restored->dimensionGrain);
        self::assertSame(ChartPresentationType::Line, $restored->presentation->chartType);
    }
}
