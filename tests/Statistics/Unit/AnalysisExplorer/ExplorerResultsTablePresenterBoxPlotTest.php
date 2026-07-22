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
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;

final class ExplorerResultsTablePresenterBoxPlotTest extends ExplorerResultsTablePresenterTestCase
{
    public function testCreateBuildsBoxPlotDistributionTable(): void
    {
        $translator = $this->stubExplorerTranslator([
            ['stats.analysis_explorer.dimension.hospital_tier', [], 'statistics', null, 'Hospital tier'],
            ['stats.analysis_explorer.box_plot.column.distribution_count', [], 'statistics', null, 'Count'],
            ...$this->boxPlotColumnTranslations(),
        ]);

        $presenter = $this->createExplorerResultsTablePresenter($translator);
        $viewConfig = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Hospitals,
            metricKeys: [AnalysisMetricKey::BedsDistribution],
            visualMetricKey: AnalysisMetricKey::BedsDistribution,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalTier),
            columnAxis: null,
            statisticsFilter: $this->publicStatisticsFilter(),
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
        $translator = $this->stubExplorerTranslator([
            ['stats.analysis_explorer.dimension.urgency', [], 'statistics', null, 'Urgency'],
            ['stats.analysis_explorer.dimension.gender', [], 'statistics', null, 'Gender'],
            ['stats.analysis_explorer.box_plot.column.distribution_count', [], 'statistics', null, 'Count'],
            ...$this->boxPlotColumnTranslations(),
        ]);

        $presenter = $this->createExplorerResultsTablePresenter($translator);
        $columnAxis = AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender);
        $viewConfig = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::TransportTimeDistribution],
            visualMetricKey: AnalysisMetricKey::TransportTimeDistribution,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Urgency),
            columnAxis: $columnAxis,
            statisticsFilter: $this->publicStatisticsFilter(),
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

    public function testCreateFormatsTransportTimeDistributionInMinutes(): void
    {
        $translator = $this->stubExplorerTranslator([
            ['stats.analysis_explorer.dimension.urgency', [], 'statistics', null, 'Urgency'],
            ['stats.analysis_explorer.box_plot.column.distribution_count', [], 'statistics', null, 'n'],
            ...$this->boxPlotColumnTranslations(),
        ]);

        $presenter = $this->createExplorerResultsTablePresenter($translator);
        $viewConfig = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::TransportTimeDistribution],
            visualMetricKey: AnalysisMetricKey::TransportTimeDistribution,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Urgency),
            columnAxis: null,
            statisticsFilter: $this->publicStatisticsFilter(),
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
