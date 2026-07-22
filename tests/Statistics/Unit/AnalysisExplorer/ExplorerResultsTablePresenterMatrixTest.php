<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisTotals;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;

final class ExplorerResultsTablePresenterMatrixTest extends ExplorerResultsTablePresenterTestCase
{
    public function testCreateBuildsEmptyTableFromResultWithoutRows(): void
    {
        $translator = $this->stubExplorerTranslator([
            ['stats.analysis_explorer.dimension.year', [], 'statistics', null, 'Year'],
            ['stats.analysis_explorer.metric.allocation_count', [], 'statistics', null, 'Allocations'],
        ]);

        $presenter = $this->createExplorerResultsTablePresenter($translator);
        $viewConfig = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::AllocationCount],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Year),
            columnAxis: null,
            statisticsFilter: $this->publicStatisticsFilter(),
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

    public function testCreateBuildsPivotTableForSeriesResult(): void
    {
        $translator = $this->stubExplorerTranslator([
            ['stats.analysis_explorer.dimension.month', [], 'statistics', null, 'Month'],
            ['stats.analysis_explorer.dimension.gender', [], 'statistics', null, 'Gender'],
            ['stats.analysis_explorer.metric.allocation_count', [], 'statistics', null, 'Allocations'],
        ]);

        $presenter = $this->createExplorerResultsTablePresenter($translator);
        $viewConfig = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::AllocationCount],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
            columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            statisticsFilter: $this->publicStatisticsFilter(),
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

    public function testCreateShowsRowPercentInMatrixTable(): void
    {
        $translator = $this->stubExplorerTranslator([
            ['stats.analysis_explorer.dimension.month', [], 'statistics', null, 'Month'],
            ['stats.analysis_explorer.dimension.gender', [], 'statistics', null, 'Gender'],
            ['stats.analysis_explorer.metric.allocation_count', [], 'statistics', null, 'Allocations'],
        ]);

        $presenter = $this->createExplorerResultsTablePresenter($translator);
        $viewConfig = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::AllocationCount, AnalysisMetricKey::PercentOfTotal],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
            columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            statisticsFilter: $this->publicStatisticsFilter(),
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
        self::assertTrue($table->isClientSortable());
        self::assertSame(4.0, $table->rows[0]->seriesValues['Male']);
        self::assertSame('66,67 %', $table->rows[0]->formattedSeriesPercentValues['Male']);
        self::assertSame('33,33 %', $table->rows[0]->formattedSeriesPercentValues['Female']);
        self::assertSame('100 %', $table->rows[0]->formattedRowTotalPercent);
    }

    public function testMatrixTableOmitsSummedTotalsForRateMetrics(): void
    {
        $translator = $this->stubExplorerTranslator([
            ['stats.analysis_explorer.dimension.month', [], 'statistics', null, 'Month'],
            ['stats.analysis_explorer.dimension.gender', [], 'statistics', null, 'Gender'],
            ['stats.analysis_explorer.metric.resus_rate', [], 'statistics', null, 'Resuscitation rate'],
        ]);

        $presenter = $this->createExplorerResultsTablePresenter($translator);
        $viewConfig = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::ResusRate],
            visualMetricKey: AnalysisMetricKey::ResusRate,
            rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
            columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            statisticsFilter: $this->publicStatisticsFilter(),
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
}
