<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerAnalysisFilterMapper;
use App\Statistics\AnalysisExplorer\Application\ExplorerAssistantConfigFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerAssistantFilters;
use App\Statistics\AnalysisExplorer\Application\ExplorerAssistantQuery;
use App\Statistics\AnalysisExplorer\Application\ExplorerTableLayoutResolver;
use App\Statistics\AnalysisExplorer\Application\ExplorerTitleFactory;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerAssistantGoal;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerAssistantReadiness;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerChartRowLimit;
use App\Statistics\AnalysisExplorer\Domain\Enum\TableLayout;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ExplorerAssistantConfigFactoryTest extends TestCase
{
    public function testTimeSeriesUsesLineChartAndMonthlyGrainByDefault(): void
    {
        $config = $this->factory()->create($this->query(
            goal: ExplorerAssistantGoal::TimeSeries,
        ), $this->filter());

        self::assertNotNull($config);
        self::assertSame(AnalysisDataSourceKey::Allocations, $config->dataSourceKey);
        self::assertSame(AnalysisDimensionKey::Time, $config->rowAxis->dimensionKey);
        self::assertSame(AnalysisDimensionGrain::Month, $config->rowAxis->resolvedGrain());
        self::assertNull($config->columnAxis);
        self::assertSame([AnalysisMetricKey::AllocationCount], $config->metricKeys);
        self::assertSame(AnalysisMetricKey::AllocationCount, $config->visualMetricKey);
        self::assertSame(ChartPresentationType::Line, $config->presentation->chartType);
        self::assertSame(TableLayout::Flat, $config->presentation->tableLayout);
        self::assertSame(ExplorerChartRowLimit::All, $config->presentation->chartRowLimit);
    }

    public function testDistributionUsesTheChosenCharacteristicAndBarChart(): void
    {
        $config = $this->factory()->create($this->query(
            goal: ExplorerAssistantGoal::Distribution,
            row: AnalysisDimensionKey::Urgency,
        ), $this->filter());

        self::assertNotNull($config);
        self::assertSame(AnalysisDimensionKey::Urgency, $config->rowAxis->dimensionKey);
        self::assertNull($config->columnAxis);
        self::assertSame(ChartPresentationType::Bar, $config->presentation->chartType);
        self::assertSame(ExplorerChartRowLimit::All, $config->presentation->chartRowLimit);
    }

    public function testToplistLimitsTheChartToTenRows(): void
    {
        $config = $this->factory()->create($this->query(
            goal: ExplorerAssistantGoal::Toplist,
            row: AnalysisDimensionKey::Indication,
        ), $this->filter());

        self::assertNotNull($config);
        self::assertSame(AnalysisDimensionKey::Indication, $config->rowAxis->dimensionKey);
        self::assertSame(ChartPresentationType::Bar, $config->presentation->chartType);
        self::assertSame(ExplorerChartRowLimit::Top10, $config->presentation->chartRowLimit);
        self::assertSame(TableLayout::Flat, $config->presentation->tableLayout);
    }

    public function testMatrixUsesHeatmapAndKeepsBothCharacteristics(): void
    {
        $config = $this->factory()->create($this->query(
            goal: ExplorerAssistantGoal::Matrix,
            row: AnalysisDimensionKey::Weekday,
            column: AnalysisDimensionKey::Hour,
        ), $this->filter());

        self::assertNotNull($config);
        self::assertSame(AnalysisDimensionKey::Weekday, $config->rowAxis->dimensionKey);
        self::assertSame(AnalysisDimensionKey::Hour, $config->columnAxis?->dimensionKey);
        self::assertSame(ChartPresentationType::Heatmap, $config->presentation->chartType);
        self::assertSame(TableLayout::Matrix, $config->presentation->tableLayout);
    }

    public function testChosenMetricBecomesTheOnlyChartMetric(): void
    {
        $config = $this->factory()->create($this->query(
            goal: ExplorerAssistantGoal::Distribution,
            row: AnalysisDimensionKey::Urgency,
            metric: AnalysisMetricKey::ResusRate,
        ), $this->filter());

        self::assertNotNull($config);
        self::assertSame([AnalysisMetricKey::ResusRate], $config->metricKeys);
        self::assertSame(AnalysisMetricKey::ResusRate, $config->visualMetricKey);
        self::assertFalse($config->showsPercentOfTotal());
        self::assertSame(ChartPresentationType::Bar, $config->presentation->chartType);
    }

    public function testDistributionWithColumnUsesHeatmap(): void
    {
        $config = $this->factory()->create($this->query(
            goal: ExplorerAssistantGoal::Distribution,
            row: AnalysisDimensionKey::Urgency,
            column: AnalysisDimensionKey::Gender,
        ), $this->filter());

        self::assertNotNull($config);
        self::assertSame(AnalysisDimensionKey::Gender, $config->columnAxis?->dimensionKey);
        self::assertSame(ChartPresentationType::Heatmap, $config->presentation->chartType);
        self::assertSame(ExplorerChartRowLimit::All, $config->presentation->chartRowLimit);
        self::assertSame(TableLayout::Matrix, $config->presentation->tableLayout);
    }

    public function testToplistWithColumnDropsTheRowLimit(): void
    {
        $config = $this->factory()->create($this->query(
            goal: ExplorerAssistantGoal::Toplist,
            row: AnalysisDimensionKey::Indication,
            column: AnalysisDimensionKey::Urgency,
        ), $this->filter());

        self::assertNotNull($config);
        self::assertSame(ChartPresentationType::Heatmap, $config->presentation->chartType);
        self::assertSame(ExplorerChartRowLimit::All, $config->presentation->chartRowLimit);
    }

    public function testTimeSeriesWithColumnStaysALineChart(): void
    {
        $config = $this->factory()->create($this->query(
            goal: ExplorerAssistantGoal::TimeSeries,
            column: AnalysisDimensionKey::Urgency,
        ), $this->filter());

        self::assertNotNull($config);
        self::assertSame(AnalysisDimensionKey::Urgency, $config->columnAxis?->dimensionKey);
        self::assertSame(ChartPresentationType::Line, $config->presentation->chartType);
        self::assertSame(TableLayout::Matrix, $config->presentation->tableLayout);
    }

    public function testDistributionProfileMetricUsesABoxPlot(): void
    {
        $config = $this->factory()->create($this->query(
            goal: ExplorerAssistantGoal::Distribution,
            row: AnalysisDimensionKey::Urgency,
            metric: AnalysisMetricKey::TransportTimeDistribution,
        ), $this->filter());

        self::assertNotNull($config);
        self::assertSame(ChartPresentationType::BoxPlot, $config->presentation->chartType);
        self::assertSame(AnalysisMetricKey::TransportTimeDistribution, $config->visualMetricKey);
    }

    public function testFiltersAreCopiedOntoTheConfig(): void
    {
        $config = $this->factory()->create($this->query(
            goal: ExplorerAssistantGoal::Distribution,
            row: AnalysisDimensionKey::Urgency,
            filters: new ExplorerAssistantFilters(gender: 2, resus: false),
        ), $this->filter());

        self::assertNotNull($config);
        self::assertCount(2, $config->filters);
        self::assertSame('gender', $config->filters[0]->dimensionKey);
        self::assertSame(2, $config->filters[0]->value);
        self::assertSame('resus', $config->filters[1]->dimensionKey);
        self::assertSame(0, $config->filters[1]->value);
    }

    public function testPercentOfTotalIsNotAChartMetric(): void
    {
        $query = $this->query(
            goal: ExplorerAssistantGoal::Distribution,
            row: AnalysisDimensionKey::Urgency,
            metric: AnalysisMetricKey::PercentOfTotal,
        );

        self::assertSame(ExplorerAssistantReadiness::Unsupported, $this->factory()->status($query));
        self::assertNull($this->factory()->create($query, $this->filter()));
    }

    public function testSameOptionalColumnIsRejected(): void
    {
        $query = $this->query(
            goal: ExplorerAssistantGoal::Distribution,
            row: AnalysisDimensionKey::Urgency,
            column: AnalysisDimensionKey::Urgency,
        );

        self::assertSame(ExplorerAssistantReadiness::SameAxis, $this->factory()->status($query));
        self::assertNull($this->factory()->create($query, $this->filter()));
    }

    public function testSameAxisIsRejected(): void
    {
        $query = $this->query(
            goal: ExplorerAssistantGoal::Matrix,
            row: AnalysisDimensionKey::Urgency,
            column: AnalysisDimensionKey::Urgency,
        );

        self::assertSame(ExplorerAssistantReadiness::SameAxis, $this->factory()->status($query));
        self::assertNull($this->factory()->create($query, $this->filter()));
    }

    public function testHospitalDistributionUsesABarChartAndAHospitalTitle(): void
    {
        $config = $this->factory()->create($this->query(
            goal: ExplorerAssistantGoal::Distribution,
            row: AnalysisDimensionKey::HospitalTier,
            metric: AnalysisMetricKey::HospitalCount,
            dataSource: AnalysisDataSourceKey::Hospitals,
        ), $this->filter());

        self::assertNotNull($config);
        self::assertSame(ChartPresentationType::Bar, $config->presentation->chartType);
        self::assertSame(ExplorerChartRowLimit::All, $config->presentation->chartRowLimit);
        self::assertSame('stats.analysis_explorer.hospitals_by_dimension', $config->title);
    }

    public function testHospitalMatrixUsesAHeatmap(): void
    {
        $config = $this->factory()->create($this->query(
            goal: ExplorerAssistantGoal::Matrix,
            row: AnalysisDimensionKey::HospitalLocation,
            column: AnalysisDimensionKey::HospitalTier,
            metric: AnalysisMetricKey::HospitalCount,
            dataSource: AnalysisDataSourceKey::Hospitals,
        ), $this->filter());

        self::assertNotNull($config);
        self::assertSame(ChartPresentationType::Heatmap, $config->presentation->chartType);
        self::assertSame(TableLayout::Matrix, $config->presentation->tableLayout);
        self::assertSame('stats.analysis_explorer.hospitals_cross_tab', $config->title);
    }

    public function testHospitalTimeSeriesStaysABarChart(): void
    {
        $config = $this->factory()->create($this->query(
            goal: ExplorerAssistantGoal::TimeSeries,
            row: AnalysisDimensionKey::HospitalSize,
            metric: AnalysisMetricKey::HospitalCount,
            dataSource: AnalysisDataSourceKey::Hospitals,
        ), $this->filter());

        self::assertNotNull($config);
        self::assertSame(ChartPresentationType::Bar, $config->presentation->chartType);
        self::assertSame(AnalysisDimensionKey::HospitalSize, $config->rowAxis->dimensionKey);
    }

    public function testDistributionWithoutCharacteristicStaysIncomplete(): void
    {
        $query = $this->query(goal: ExplorerAssistantGoal::Distribution);

        self::assertSame(ExplorerAssistantReadiness::Incomplete, $this->factory()->status($query));
        self::assertNull($this->factory()->create($query, $this->filter()));
    }

    public function testExplorerDimensionOutsideTheAssistantCatalogIsReady(): void
    {
        $query = $this->query(
            goal: ExplorerAssistantGoal::Distribution,
            row: AnalysisDimensionKey::Hour,
        );

        $config = $this->factory()->create($query, $this->filter());

        self::assertSame(ExplorerAssistantReadiness::Ready, $this->factory()->status($query));
        self::assertNotNull($config);
        self::assertSame(AnalysisDimensionKey::Hour, $config->rowAxis->dimensionKey);
        self::assertSame(ChartPresentationType::Bar, $config->presentation->chartType);
    }

    private function factory(): ExplorerAssistantConfigFactory
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new ExplorerAssistantConfigFactory(
            new ExplorerTitleFactory($translator),
            new ExplorerTableLayoutResolver(),
            new ExplorerAnalysisFilterMapper(),
        );
    }

    private function query(
        ExplorerAssistantGoal $goal,
        ?AnalysisDimensionKey $row = null,
        ?AnalysisDimensionKey $column = null,
        ?AnalysisDimensionGrain $grain = null,
        ?AnalysisMetricKey $metric = null,
        ?ExplorerAssistantFilters $filters = null,
        AnalysisDataSourceKey $dataSource = AnalysisDataSourceKey::Allocations,
    ): ExplorerAssistantQuery {
        return new ExplorerAssistantQuery(
            $goal,
            $row,
            $column,
            $grain,
            null,
            $metric ?? AnalysisMetricKey::AllocationCount,
            $dataSource,
            $filters ?? ExplorerAssistantFilters::none(),
            false,
        );
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
}
