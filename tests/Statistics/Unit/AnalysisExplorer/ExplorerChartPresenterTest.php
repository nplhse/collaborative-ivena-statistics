<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisTotals;
use App\Statistics\AnalysisExplorer\Domain\DTO\BoxPlotStats;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerChartRowLimit;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Tests\Statistics\Support\AnalysisExplorerTestSupport;
use PHPUnit\Framework\TestCase;

final class ExplorerChartPresenterTest extends TestCase
{
    use AnalysisExplorerTestSupport;

    public function testLimitsCategoricalRowsWhenTopFiveConfigured(): void
    {
        $presenter = $this->createExplorerChartPresenter();
        $rows = [];
        foreach ([50, 40, 30, 20, 10, 5, 1] as $index => $value) {
            $rows[] = new AnalysisResultRow(
                bucket: (string) $index,
                bucketLabel: 'Spec '.$index,
                seriesKey: null,
                seriesLabel: null,
                metricValues: ['allocation_count' => $value],
            );
        }

        $result = new AnalysisRunResult(
            title: 'Speciality distribution',
            metricKeys: [AnalysisMetricKey::AllocationCount],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Department),
            columnAxis: null,
            rows: $rows,
            totals: new AnalysisTotals(grand: ['allocation_count' => 156]),
        );

        $limitedPresentation = new PresentationConfig(
            chartType: ChartPresentationType::Bar,
            chartRowLimit: ExplorerChartRowLimit::Top5,
        );
        $specs = $presenter->buildSpecs($result, $limitedPresentation);

        self::assertCount(5, $specs['bar']['labels']);
        self::assertSame('Spec 0', $specs['bar']['labels'][0]);
        self::assertNotContains('Other', $specs['bar']['labels']);

        $allPresentation = new PresentationConfig(
            chartType: ChartPresentationType::Bar,
            chartRowLimit: ExplorerChartRowLimit::All,
        );
        $fullSpecs = $presenter->buildSpecs($result, $allPresentation);

        self::assertCount(7, $fullSpecs['bar']['labels']);
    }

    public function testDoesNotLimitTemporalRowAxis(): void
    {
        $presenter = $this->createExplorerChartPresenter();
        $rows = [];
        foreach (range(1, 8) as $month) {
            $rows[] = new AnalysisResultRow(
                bucket: sprintf('2024-%02d', $month),
                bucketLabel: sprintf('2024-%02d', $month),
                seriesKey: null,
                seriesLabel: null,
                metricValues: ['allocation_count' => $month],
            );
        }

        $result = new AnalysisRunResult(
            title: 'Allocations over time',
            metricKeys: [AnalysisMetricKey::AllocationCount],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
            columnAxis: null,
            rows: $rows,
            totals: new AnalysisTotals(grand: ['allocation_count' => 36]),
        );

        $presentation = new PresentationConfig(
            chartType: ChartPresentationType::Bar,
            chartRowLimit: ExplorerChartRowLimit::Top5,
        );
        $specs = $presenter->buildSpecs($result, $presentation);

        self::assertCount(8, $specs['bar']['labels']);
    }

    public function testBuildsBoxPlotSpecForDistributionProfile(): void
    {
        $presenter = $this->createExplorerChartPresenter();
        $result = new AnalysisRunResult(
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
        );

        $specs = $presenter->buildSpecs($result, new PresentationConfig(chartType: ChartPresentationType::BoxPlot));

        self::assertSame('boxPlot', $specs['box_plot']['chartType']);
        self::assertSame('Tier A', $specs['box_plot']['series'][0]['data'][0]['x']);
        self::assertSame([10.0, 20.0, 50.0, 80.0, 100.0], $specs['box_plot']['series'][0]['data'][0]['y']);
    }

    public function testBuildsBoxPlotSpecWithMinutesFormatForTransportTimeProfile(): void
    {
        $presenter = $this->createExplorerChartPresenter();
        $result = new AnalysisRunResult(
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
        );

        $specs = $presenter->buildSpecs($result, new PresentationConfig(chartType: ChartPresentationType::BoxPlot));

        self::assertSame('minutes', $specs['box_plot']['valueFormat']);
    }

    public function testBuildsMultiSeriesBoxPlotSpecForDistributionMatrix(): void
    {
        $presenter = $this->createExplorerChartPresenter();
        $result = new AnalysisRunResult(
            title: 'Transport time by urgency and gender',
            metricKeys: [AnalysisMetricKey::TransportTimeDistribution],
            visualMetricKey: AnalysisMetricKey::TransportTimeDistribution,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Urgency),
            columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            rows: [
                new AnalysisResultRow(
                    bucket: '1',
                    bucketLabel: 'Urgent',
                    seriesKey: '1',
                    seriesLabel: 'Male',
                    metricValues: ['transport_time_distribution' => 25.0],
                    boxPlot: new BoxPlotStats(5, 10.0, 15.0, 25.0, 35.0, 45.0),
                ),
                new AnalysisResultRow(
                    bucket: '1',
                    bucketLabel: 'Urgent',
                    seriesKey: '2',
                    seriesLabel: 'Female',
                    metricValues: ['transport_time_distribution' => 30.0],
                    boxPlot: new BoxPlotStats(4, 12.0, 18.0, 30.0, 38.0, 48.0),
                ),
            ],
            totals: new AnalysisTotals(grand: ['transport_time_distribution' => 27.5]),
        );

        $specs = $presenter->buildSpecs($result, new PresentationConfig(chartType: ChartPresentationType::BoxPlot));

        self::assertTrue($specs['box_plot']['multiSeries']);
        self::assertCount(2, $specs['box_plot']['series']);
        self::assertSame('Male', $specs['box_plot']['series'][0]['name']);
        self::assertSame('Urgent', $specs['box_plot']['series'][0]['data'][0]['x']);
    }

    public function testBuildsClinicalResourcesPrevalenceBarSpec(): void
    {
        $presenter = $this->createExplorerChartPresenter();
        $result = new AnalysisRunResult(
            title: 'Clinical resources overview',
            metricKeys: [AnalysisMetricKey::PrevalenceRate],
            visualMetricKey: AnalysisMetricKey::PrevalenceRate,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::ClinicalResources),
            columnAxis: null,
            rows: [
                new AnalysisResultRow(
                    bucket: 'resus',
                    bucketLabel: 'Resuscitation required',
                    seriesKey: null,
                    seriesLabel: null,
                    metricValues: ['prevalence_rate' => 12.5],
                ),
                new AnalysisResultRow(
                    bucket: 'cathlab',
                    bucketLabel: 'Cath lab required',
                    seriesKey: null,
                    seriesLabel: null,
                    metricValues: ['prevalence_rate' => 8.0],
                ),
            ],
            totals: new AnalysisTotals(grand: ['prevalence_rate' => null]),
        );

        $specs = $presenter->buildSpecs($result, new PresentationConfig(chartType: ChartPresentationType::Bar));

        self::assertSame('bar', $specs['bar']['chartType']);
        self::assertSame(['Resuscitation required', 'Cath lab required'], $specs['bar']['labels']);
        self::assertSame([12.5, 8.0], $specs['bar']['values']);
        self::assertTrue($specs['bar']['percentScale']);
    }
}
