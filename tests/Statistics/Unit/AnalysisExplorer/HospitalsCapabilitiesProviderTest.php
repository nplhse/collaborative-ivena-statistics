<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerHospitalPopulationMode;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Tests\Statistics\Support\AnalysisExplorerTestSupport;
use PHPUnit\Framework\TestCase;

final class HospitalsCapabilitiesProviderTest extends TestCase
{
    use AnalysisExplorerTestSupport;

    public function testComparePopulationModeAllowsGroupedBarWithoutColumnAxis(): void
    {
        $capabilities = $this->createHospitalsCapabilitiesProvider()->capabilitiesFor(null, new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        ));

        $config = new AnalysisViewConfig(
            dataSourceKey: $capabilities->dataSourceKey,
            metricKeys: [AnalysisMetricKey::HospitalCount],
            visualMetricKey: AnalysisMetricKey::HospitalCount,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalTier),
            columnAxis: null,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(chartType: ChartPresentationType::GroupedBar),
            title: 'Hospitals by tier (participation compare)',
            hospitalPopulationMode: ExplorerHospitalPopulationMode::Compare,
        );

        self::assertTrue($capabilities->usesMultiSeriesChart($config));
        self::assertSame(
            [
                ChartPresentationType::GroupedBar,
                ChartPresentationType::StackedBar,
                ChartPresentationType::Line,
                ChartPresentationType::Heatmap,
            ],
            $capabilities->chartTypesFor($config),
        );
        self::assertTrue($capabilities->supports($config));
        self::assertNotContains(AnalysisDimensionKey::HospitalEntity, $capabilities->dimensions);
    }

    public function testColumnAxisOffersOnlyMultiSeriesChartTypes(): void
    {
        $capabilities = $this->hospitalCapabilities();
        $config = $this->hospitalConfig(
            chartType: ChartPresentationType::GroupedBar,
            columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalLocation),
        );

        self::assertSame(
            [
                ChartPresentationType::GroupedBar,
                ChartPresentationType::StackedBar,
                ChartPresentationType::Line,
                ChartPresentationType::Heatmap,
            ],
            $capabilities->chartTypesFor($config),
        );
    }

    public function testSingleSeriesOffersOnlyBarAndLine(): void
    {
        $capabilities = $this->hospitalCapabilities();

        self::assertSame(
            [ChartPresentationType::Bar, ChartPresentationType::Line],
            $capabilities->chartTypesFor($this->hospitalConfig()),
        );
    }

    public function testDistributionProfileOffersOnlyBoxPlot(): void
    {
        $capabilities = $this->hospitalCapabilities();
        $config = $this->hospitalConfig(
            metric: AnalysisMetricKey::BedsDistribution,
            chartType: ChartPresentationType::BoxPlot,
        );

        self::assertSame([ChartPresentationType::BoxPlot], $capabilities->chartTypesFor($config));
        self::assertSame(ChartPresentationType::BoxPlot, $capabilities->defaultChartTypeFor($config));
    }

    private function hospitalCapabilities(): DataSourceCapabilities
    {
        return $this->createHospitalsCapabilitiesProvider()->capabilitiesFor(null, new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        ));
    }

    private function hospitalConfig(
        AnalysisMetricKey $metric = AnalysisMetricKey::HospitalCount,
        ChartPresentationType $chartType = ChartPresentationType::Bar,
        ?AnalysisAxisRef $columnAxis = null,
        ExplorerHospitalPopulationMode $populationMode = ExplorerHospitalPopulationMode::Participating,
    ): AnalysisViewConfig {
        return new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Hospitals,
            metricKeys: [$metric],
            visualMetricKey: $metric,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalTier),
            columnAxis: $columnAxis,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(chartType: $chartType),
            title: 'Hospitals',
            hospitalPopulationMode: $populationMode,
        );
    }
}
