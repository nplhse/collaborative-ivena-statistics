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
}
