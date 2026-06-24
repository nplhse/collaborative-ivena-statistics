<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisTotals;
use App\Statistics\AnalysisExplorer\Domain\DTO\BoxPlotStats;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Tests\Statistics\Support\AnalysisExplorerTestSupport;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ExplorerResultsTablePresenterTest extends TestCase
{
    use AnalysisExplorerTestSupport;

    public function testCreateBuildsRowsAndTotalFromResult(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnMap([
            ['stats.analysis_explorer.dimension.month', [], null, null, 'Month'],
            ['stats.analysis_explorer.metric.allocation_count', [], null, null, 'Allocations'],
        ]);

        $presenter = $this->createExplorerResultsTablePresenter($translator);
        $viewConfig = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::AllocationCount],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
            columnAxis: null,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(chartType: ChartPresentationType::Bar),
            title: 'Allocations over time',
        );

        $table = $presenter->create(
            $viewConfig,
            new AnalysisRunResult(
                title: 'Allocations over time',
                metricKeys: [AnalysisMetricKey::AllocationCount],
                visualMetricKey: AnalysisMetricKey::AllocationCount,
                rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
                columnAxis: null,
                rows: [
                    new AnalysisResultRow(
                        bucket: '2024-06',
                        bucketLabel: 'Jun 2024',
                        seriesKey: null,
                        seriesLabel: null,
                        metricValues: ['allocation_count' => 12],
                    ),
                    new AnalysisResultRow(
                        bucket: '2024-07',
                        bucketLabel: 'Jul 2024',
                        seriesKey: null,
                        seriesLabel: null,
                        metricValues: ['allocation_count' => 8],
                    ),
                ],
                totals: new AnalysisTotals(grand: ['allocation_count' => 20]),
            ),
        );

        self::assertSame('Month', $table->primaryDimensionLabel);
        self::assertSame('Allocations', $table->metricColumns[0]->label);
        self::assertTrue($table->hasData());
        self::assertSame('20', $table->formattedTotals['allocation_count']);
        self::assertCount(2, $table->rows);
        self::assertSame('Jun 2024', $table->rows[0]->bucketLabel);
        self::assertSame('12', $table->rows[0]->formattedMetricValues['allocation_count']);
    }

    public function testCreateShowsInlinePercentWithoutSeparateColumn(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnMap([
            ['stats.analysis_explorer.dimension.gender', [], null, null, 'Gender'],
            ['stats.analysis_explorer.metric.allocation_count', [], null, null, 'Allocations'],
        ]);

        $presenter = $this->createExplorerResultsTablePresenter($translator);
        $viewConfig = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::AllocationCount, AnalysisMetricKey::PercentOfTotal],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            columnAxis: null,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(chartType: ChartPresentationType::Bar),
            title: 'Gender breakdown',
        );

        $table = $presenter->create(
            $viewConfig,
            new AnalysisRunResult(
                title: 'Gender breakdown',
                metricKeys: [AnalysisMetricKey::AllocationCount, AnalysisMetricKey::PercentOfTotal],
                visualMetricKey: AnalysisMetricKey::AllocationCount,
                rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
                columnAxis: null,
                rows: [
                    new AnalysisResultRow('male', 'Male', null, null, [
                        'allocation_count' => 30,
                        'percent_of_total' => 60.0,
                    ]),
                    new AnalysisResultRow('female', 'Female', null, null, [
                        'allocation_count' => 20,
                        'percent_of_total' => 40.0,
                    ]),
                ],
                totals: new AnalysisTotals(grand: [
                    'allocation_count' => 50,
                    'percent_of_total' => 100.0,
                ]),
            ),
        );

        self::assertTrue($table->showPercentOfTotal);
        self::assertCount(1, $table->metricColumns);
        self::assertSame('allocation_count', $table->metricColumns[0]->key);
        self::assertSame('60 %', $table->rows[0]->formattedMetricPercentValues['allocation_count']);
        self::assertSame('100 %', $table->formattedTotalsPercentValues['allocation_count']);
    }

    public function testCreateShowsRowPercentInMatrixTable(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnMap([
            ['stats.analysis_explorer.dimension.month', [], null, null, 'Month'],
            ['stats.analysis_explorer.dimension.gender', [], null, null, 'Gender'],
            ['stats.analysis_explorer.metric.allocation_count', [], null, null, 'Allocations'],
        ]);

        $presenter = $this->createExplorerResultsTablePresenter($translator);
        $viewConfig = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::AllocationCount, AnalysisMetricKey::PercentOfTotal],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
            columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(
                chartType: ChartPresentationType::GroupedBar,
                tableLayout: \App\Statistics\AnalysisExplorer\Domain\Enum\TableLayout::Matrix,
            ),
            title: 'Allocations by gender over time',
        );

        $table = $presenter->create(
            $viewConfig,
            new AnalysisRunResult(
                title: 'Allocations by gender over time',
                metricKeys: [AnalysisMetricKey::AllocationCount, AnalysisMetricKey::PercentOfTotal],
                visualMetricKey: AnalysisMetricKey::AllocationCount,
                rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
                columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
                rows: [
                    new AnalysisResultRow(
                        bucket: '2024-06',
                        bucketLabel: 'Jun 2024',
                        seriesKey: '1',
                        seriesLabel: 'Male',
                        metricValues: ['allocation_count' => 4],
                    ),
                    new AnalysisResultRow(
                        bucket: '2024-06',
                        bucketLabel: 'Jun 2024',
                        seriesKey: '2',
                        seriesLabel: 'Female',
                        metricValues: ['allocation_count' => 2],
                    ),
                ],
                totals: new AnalysisTotals(grand: ['allocation_count' => 6]),
            ),
        );

        self::assertTrue($table->showPercentOfTotal);
        self::assertSame('66,67 %', $table->rows[0]->formattedSeriesPercentValues['Male']);
        self::assertSame('33,33 %', $table->rows[0]->formattedSeriesPercentValues['Female']);
        self::assertSame('100 %', $table->rows[0]->formattedRowTotalPercent);
    }

    public function testCreateBuildsPivotTableForSeriesResult(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnMap([
            ['stats.analysis_explorer.dimension.month', [], null, null, 'Month'],
            ['stats.analysis_explorer.dimension.gender', [], null, null, 'Gender'],
            ['stats.analysis_explorer.metric.allocation_count', [], null, null, 'Allocations'],
        ]);

        $presenter = $this->createExplorerResultsTablePresenter($translator);
        $viewConfig = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::AllocationCount],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
            columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(
                chartType: ChartPresentationType::GroupedBar,
                tableLayout: \App\Statistics\AnalysisExplorer\Domain\Enum\TableLayout::Matrix,
            ),
            title: 'Allocations by gender over time',
        );

        $table = $presenter->create(
            $viewConfig,
            new AnalysisRunResult(
                title: 'Allocations by gender over time',
                metricKeys: [AnalysisMetricKey::AllocationCount],
                visualMetricKey: AnalysisMetricKey::AllocationCount,
                rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
                columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
                rows: [
                    new AnalysisResultRow(
                        bucket: '2024-06',
                        bucketLabel: 'Jun 2024',
                        seriesKey: '1',
                        seriesLabel: 'Male',
                        metricValues: ['allocation_count' => 4],
                    ),
                    new AnalysisResultRow(
                        bucket: '2024-06',
                        bucketLabel: 'Jun 2024',
                        seriesKey: '2',
                        seriesLabel: 'Female',
                        metricValues: ['allocation_count' => 2],
                    ),
                ],
                totals: new AnalysisTotals(grand: ['allocation_count' => 6]),
            ),
        );

        self::assertTrue($table->hasSeries);
        self::assertSame(['Male', 'Female'], $table->seriesLabels);
        self::assertCount(1, $table->rows);
        self::assertSame(6.0, $table->rows[0]->rowTotal);
        self::assertSame(4.0, $table->rows[0]->seriesValues['Male']);
        self::assertSame('4', $table->rows[0]->formattedSeriesValues['Male']);
        self::assertSame('2', $table->rows[0]->formattedSeriesValues['Female']);
        self::assertSame('6', $table->formattedGrandTotal);
    }

    public function testCreateBuildsEmptyTableFromResultWithoutRows(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnMap([
            ['stats.analysis_explorer.dimension.year', [], null, null, 'Year'],
            ['stats.analysis_explorer.metric.allocation_count', [], null, null, 'Allocations'],
        ]);

        $presenter = $this->createExplorerResultsTablePresenter($translator);
        $viewConfig = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::AllocationCount],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Year),
            columnAxis: null,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(chartType: ChartPresentationType::Bar),
            title: 'Allocations over time',
        );

        $table = $presenter->create(
            $viewConfig,
            new AnalysisRunResult(
                title: 'Allocations over time',
                metricKeys: [AnalysisMetricKey::AllocationCount],
                visualMetricKey: AnalysisMetricKey::AllocationCount,
                rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Year),
                columnAxis: null,
                rows: [],
                totals: new AnalysisTotals(grand: ['allocation_count' => 0]),
            ),
        );

        self::assertFalse($table->hasData());
        self::assertSame('0', $table->formattedTotals['allocation_count']);
        self::assertCount(0, $table->rows);
    }

    public function testMatrixTableOmitsSummedTotalsForRateMetrics(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnMap([
            ['stats.analysis_explorer.dimension.month', [], null, null, 'Month'],
            ['stats.analysis_explorer.dimension.gender', [], null, null, 'Gender'],
            ['stats.analysis_explorer.metric.resus_rate', [], null, null, 'Resuscitation rate'],
        ]);

        $presenter = $this->createExplorerResultsTablePresenter($translator);
        $viewConfig = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::ResusRate],
            visualMetricKey: AnalysisMetricKey::ResusRate,
            rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
            columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(
                chartType: ChartPresentationType::GroupedBar,
                tableLayout: \App\Statistics\AnalysisExplorer\Domain\Enum\TableLayout::Matrix,
            ),
            title: 'Resuscitation rate by gender over time',
        );

        $table = $presenter->create(
            $viewConfig,
            new AnalysisRunResult(
                title: 'Resuscitation rate by gender over time',
                metricKeys: [AnalysisMetricKey::ResusRate],
                visualMetricKey: AnalysisMetricKey::ResusRate,
                rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
                columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
                rows: [
                    new AnalysisResultRow(
                        bucket: '2024-06',
                        bucketLabel: 'Jun 2024',
                        seriesKey: '1',
                        seriesLabel: 'Male',
                        metricValues: ['resus_rate' => 10.0],
                    ),
                    new AnalysisResultRow(
                        bucket: '2024-06',
                        bucketLabel: 'Jun 2024',
                        seriesKey: '2',
                        seriesLabel: 'Female',
                        metricValues: ['resus_rate' => 20.0],
                    ),
                ],
                totals: new AnalysisTotals(grand: ['resus_rate' => null]),
            ),
        );

        self::assertFalse($table->showPercentOfTotal);
        self::assertSame('10 %', $table->rows[0]->formattedSeriesValues['Male']);
        self::assertSame('20 %', $table->rows[0]->formattedSeriesValues['Female']);
        self::assertSame('—', $table->rows[0]->formattedRowTotal);
        self::assertSame(0.0, $table->rows[0]->rowTotal);
        self::assertSame('—', $table->formattedSeriesTotals['Male']);
        self::assertSame('—', $table->formattedSeriesTotals['Female']);
        self::assertSame('—', $table->formattedGrandTotal);
        self::assertSame('—', $table->formattedTotals['resus_rate']);
    }

    public function testCreateBuildsBoxPlotDistributionTable(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnMap([
            ['stats.analysis_explorer.dimension.hospital_tier', [], null, null, 'Hospital tier'],
            ['stats.analysis_explorer.box_plot.column.distribution_count', [], null, null, 'Count'],
            ['stats.analysis_explorer.box_plot.column.distribution_min', [], null, null, 'Min'],
            ['stats.analysis_explorer.box_plot.column.distribution_p25', [], null, null, 'P25'],
            ['stats.analysis_explorer.box_plot.column.distribution_median', [], null, null, 'Median'],
            ['stats.analysis_explorer.box_plot.column.distribution_p75', [], null, null, 'P75'],
            ['stats.analysis_explorer.box_plot.column.distribution_max', [], null, null, 'Max'],
        ]);

        $presenter = $this->createExplorerResultsTablePresenter($translator);
        $viewConfig = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Hospitals,
            metricKeys: [AnalysisMetricKey::BedsDistribution],
            visualMetricKey: AnalysisMetricKey::BedsDistribution,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalTier),
            columnAxis: null,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(chartType: ChartPresentationType::BoxPlot),
            title: 'Beds distribution by tier',
        );

        $table = $presenter->create(
            $viewConfig,
            new AnalysisRunResult(
                title: 'Beds distribution by tier',
                metricKeys: [AnalysisMetricKey::BedsDistribution],
                visualMetricKey: AnalysisMetricKey::BedsDistribution,
                rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalTier),
                columnAxis: null,
                rows: [
                    new AnalysisResultRow(
                        bucket: 'tier_a',
                        bucketLabel: 'Tier A',
                        seriesKey: null,
                        seriesLabel: null,
                        metricValues: ['beds_distribution' => 50.0],
                        boxPlot: new BoxPlotStats(3, 10.0, 20.0, 50.0, 80.0, 100.0),
                    ),
                ],
                totals: new AnalysisTotals(grand: ['beds_distribution' => 50.0]),
            ),
        );

        self::assertSame('Count', $table->metricColumns[0]->label);
        self::assertSame('Median', $table->metricColumns[3]->label);
        self::assertSame('3', $table->rows[0]->formattedMetricValues['distribution_count']);
        self::assertSame('50', $table->rows[0]->formattedMetricValues['distribution_median']);
        self::assertSame('100', $table->rows[0]->formattedMetricValues['distribution_max']);
    }

    public function testCreateBuildsTwoDimensionalBoxPlotDistributionTableWithSeriesColumn(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnMap([
            ['stats.analysis_explorer.dimension.urgency', [], null, null, 'Urgency'],
            ['stats.analysis_explorer.dimension.gender', [], null, null, 'Gender'],
            ['stats.analysis_explorer.box_plot.column.distribution_count', [], null, null, 'Count'],
            ['stats.analysis_explorer.box_plot.column.distribution_min', [], null, null, 'Min'],
            ['stats.analysis_explorer.box_plot.column.distribution_p25', [], null, null, 'P25'],
            ['stats.analysis_explorer.box_plot.column.distribution_median', [], null, null, 'Median'],
            ['stats.analysis_explorer.box_plot.column.distribution_p75', [], null, null, 'P75'],
            ['stats.analysis_explorer.box_plot.column.distribution_max', [], null, null, 'Max'],
        ]);

        $presenter = $this->createExplorerResultsTablePresenter($translator);
        $columnAxis = AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender);
        $viewConfig = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::TransportTimeDistribution],
            visualMetricKey: AnalysisMetricKey::TransportTimeDistribution,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Urgency),
            columnAxis: $columnAxis,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(chartType: ChartPresentationType::BoxPlot),
            title: 'Transport time by urgency and gender',
        );

        $table = $presenter->create(
            $viewConfig,
            new AnalysisRunResult(
                title: 'Transport time by urgency and gender',
                metricKeys: [AnalysisMetricKey::TransportTimeDistribution],
                visualMetricKey: AnalysisMetricKey::TransportTimeDistribution,
                rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Urgency),
                columnAxis: $columnAxis,
                rows: [
                    new AnalysisResultRow(
                        bucket: '1',
                        bucketLabel: 'High',
                        seriesKey: 'male',
                        seriesLabel: 'Male',
                        metricValues: ['transport_time_distribution' => 25.0],
                        boxPlot: new BoxPlotStats(4, 10.0, 15.0, 25.0, 35.0, 45.0),
                    ),
                    new AnalysisResultRow(
                        bucket: '1',
                        bucketLabel: 'High',
                        seriesKey: 'female',
                        seriesLabel: 'Female',
                        metricValues: ['transport_time_distribution' => 30.0],
                        boxPlot: new BoxPlotStats(3, 12.0, 18.0, 30.0, 40.0, 50.0),
                    ),
                ],
                totals: new AnalysisTotals(grand: ['transport_time_distribution' => 55.0]),
            ),
        );

        self::assertSame('Gender', $table->metricColumns[0]->label);
        self::assertSame('series', $table->metricColumns[0]->key);
        self::assertSame('Count', $table->metricColumns[1]->label);
        self::assertCount(2, $table->rows);
        self::assertSame('High', $table->rows[0]->bucketLabel);
        self::assertSame('Male', $table->rows[0]->formattedMetricValues['series']);
        self::assertSame('25 min', $table->rows[0]->formattedMetricValues['distribution_median']);
        self::assertSame('Female', $table->rows[1]->formattedMetricValues['series']);
        self::assertSame('30 min', $table->rows[1]->formattedMetricValues['distribution_median']);
        self::assertSame('Gender', $table->columnAxisLabel);
    }

    public function testCreateShowsMultipleHospitalMetricsInFlatTable(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnMap([
            ['stats.analysis_explorer.dimension.hospital_tier', [], null, null, 'Hospital tier'],
            ['stats.analysis_explorer.metric.hospital_count', [], null, null, 'Hospitals'],
            ['stats.analysis_explorer.metric.avg_beds', [], null, null, 'Average beds'],
        ]);

        $presenter = $this->createExplorerResultsTablePresenter($translator);
        $viewConfig = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Hospitals,
            metricKeys: [AnalysisMetricKey::HospitalCount, AnalysisMetricKey::AvgBeds],
            visualMetricKey: AnalysisMetricKey::HospitalCount,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalTier),
            columnAxis: null,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(chartType: ChartPresentationType::Bar),
            title: 'Hospitals by tier',
        );

        $table = $presenter->create(
            $viewConfig,
            new AnalysisRunResult(
                title: 'Hospitals by tier',
                metricKeys: [AnalysisMetricKey::HospitalCount, AnalysisMetricKey::AvgBeds],
                visualMetricKey: AnalysisMetricKey::HospitalCount,
                rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalTier),
                columnAxis: null,
                rows: [
                    new AnalysisResultRow(
                        bucket: 'tier_a',
                        bucketLabel: 'Tier A',
                        seriesKey: null,
                        seriesLabel: null,
                        metricValues: [
                            'hospital_count' => 5,
                            'avg_beds' => 120.5,
                        ],
                    ),
                ],
                totals: new AnalysisTotals(grand: [
                    'hospital_count' => 5,
                    'avg_beds' => 120.5,
                ]),
            ),
        );

        self::assertCount(2, $table->metricColumns);
        self::assertSame('hospital_count', $table->metricColumns[0]->key);
        self::assertSame('avg_beds', $table->metricColumns[1]->key);
        self::assertSame('5', $table->rows[0]->formattedMetricValues['hospital_count']);
        self::assertSame('120,5', $table->rows[0]->formattedMetricValues['avg_beds']);
    }

    public function testCreateFormatsTransportTimeDistributionInMinutes(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnMap([
            ['stats.analysis_explorer.dimension.urgency', [], null, null, 'Urgency'],
            ['stats.analysis_explorer.box_plot.column.distribution_count', [], null, null, 'n'],
            ['stats.analysis_explorer.box_plot.column.distribution_min', [], null, null, 'Min'],
            ['stats.analysis_explorer.box_plot.column.distribution_p25', [], null, null, 'P25'],
            ['stats.analysis_explorer.box_plot.column.distribution_median', [], null, null, 'Median'],
            ['stats.analysis_explorer.box_plot.column.distribution_p75', [], null, null, 'P75'],
            ['stats.analysis_explorer.box_plot.column.distribution_max', [], null, null, 'Max'],
        ]);

        $presenter = $this->createExplorerResultsTablePresenter($translator);
        $viewConfig = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::TransportTimeDistribution],
            visualMetricKey: AnalysisMetricKey::TransportTimeDistribution,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Urgency),
            columnAxis: null,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(chartType: ChartPresentationType::BoxPlot),
            title: 'Transport time distribution by urgency',
        );

        $table = $presenter->create(
            $viewConfig,
            new AnalysisRunResult(
                title: 'Transport time distribution by urgency',
                metricKeys: [AnalysisMetricKey::TransportTimeDistribution],
                visualMetricKey: AnalysisMetricKey::TransportTimeDistribution,
                rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Urgency),
                columnAxis: null,
                rows: [
                    new AnalysisResultRow(
                        bucket: '1',
                        bucketLabel: 'Urgent',
                        seriesKey: null,
                        seriesLabel: null,
                        metricValues: ['transport_time_distribution' => 25.0],
                        boxPlot: new BoxPlotStats(5, 10.0, 15.0, 25.0, 35.0, 45.0),
                    ),
                ],
                totals: new AnalysisTotals(grand: ['transport_time_distribution' => 25.0]),
            ),
        );

        self::assertSame('25 min', $table->rows[0]->formattedMetricValues['distribution_median']);
        self::assertSame('10 min', $table->rows[0]->formattedMetricValues['distribution_min']);
    }
}
