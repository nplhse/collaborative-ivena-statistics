<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerMetricProfileRegistry;
use App\Statistics\AnalysisExplorer\Application\ExplorerMetricSummabilityPolicy;
use App\Statistics\AnalysisExplorer\Application\ExplorerResultsTableExportBuilder;
use App\Statistics\AnalysisExplorer\Application\ExplorerTablePercentHelper;
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
use App\Statistics\AnalysisExplorer\Domain\Enum\TableLayout;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\GenericAnalysis\Application\Export\CsvTabularExporter;
use App\Statistics\GenericAnalysis\Application\MetricValueFormatter;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ExplorerResultsTableExportBuilderTest extends TestCase
{
    public function testFlatLayoutExportsRawValuesAndFooter(): void
    {
        $builder = $this->createBuilder();
        $viewConfig = $this->viewConfig(columnAxis: null);

        $document = $builder->build(
            $viewConfig,
            new AnalysisRunResult(
                title: 'Allocations over time',
                metricKeys: [AnalysisMetricKey::AllocationCount],
                visualMetricKey: AnalysisMetricKey::AllocationCount,
                rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
                columnAxis: null,
                rows: [
                    new AnalysisResultRow('2024-06', 'Jun 2024', null, null, ['allocation_count' => 12]),
                    new AnalysisResultRow('2024-07', 'Jul 2024', null, null, ['allocation_count' => 8]),
                ],
                totals: new AnalysisTotals(grand: ['allocation_count' => 20]),
            ),
        );

        $csv = $this->exportToString($document);

        self::assertStringContainsString("Month,Allocations\n", $csv);
        self::assertStringContainsString('"Jun 2024",12'."\n", $csv);
        self::assertStringContainsString('"Jul 2024",8'."\n", $csv);
        self::assertStringContainsString("Total,20\n", $csv);
    }

    public function testFlatLayoutWithPercentExportsAdditionalColumn(): void
    {
        $builder = $this->createBuilder();
        $viewConfig = $this->viewConfig(
            columnAxis: null,
            metricKeys: [AnalysisMetricKey::AllocationCount, AnalysisMetricKey::PercentOfTotal],
        );

        $document = $builder->build(
            $viewConfig,
            new AnalysisRunResult(
                title: 'Allocations over time',
                metricKeys: [AnalysisMetricKey::AllocationCount, AnalysisMetricKey::PercentOfTotal],
                visualMetricKey: AnalysisMetricKey::AllocationCount,
                rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
                columnAxis: null,
                rows: [
                    new AnalysisResultRow('2024-06', 'Jun 2024', null, null, [
                        'allocation_count' => 12,
                        'percent_of_total' => 60.0,
                    ]),
                    new AnalysisResultRow('2024-07', 'Jul 2024', null, null, [
                        'allocation_count' => 8,
                        'percent_of_total' => 40.0,
                    ]),
                ],
                totals: new AnalysisTotals(grand: [
                    'allocation_count' => 20,
                    'percent_of_total' => 100.0,
                ]),
            ),
        );

        $csv = $this->exportToString($document);

        self::assertStringContainsString('Allocations,"% of total"', $csv);
        self::assertStringContainsString('"Jun 2024",12,60'."\n", $csv);
        self::assertStringContainsString("Total,20,100\n", $csv);
    }

    public function testFlatLayoutWithPercentExportsPercentColumnForEachSummableMetric(): void
    {
        $builder = $this->createBuilder();
        $viewConfig = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Hospitals,
            metricKeys: [
                AnalysisMetricKey::HospitalCount,
                AnalysisMetricKey::SumBeds,
                AnalysisMetricKey::AvgBeds,
                AnalysisMetricKey::PercentOfTotal,
            ],
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

        $document = $builder->build(
            $viewConfig,
            new AnalysisRunResult(
                title: 'Hospitals by tier',
                metricKeys: [
                    AnalysisMetricKey::HospitalCount,
                    AnalysisMetricKey::SumBeds,
                    AnalysisMetricKey::AvgBeds,
                    AnalysisMetricKey::PercentOfTotal,
                ],
                visualMetricKey: AnalysisMetricKey::HospitalCount,
                rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalTier),
                columnAxis: null,
                rows: [
                    new AnalysisResultRow('full', 'Full', null, null, [
                        'hospital_count' => 10,
                        'sum_beds' => 2345,
                        'avg_beds' => 234.5,
                        'percent_of_total' => 38.46,
                    ]),
                ],
                totals: new AnalysisTotals(grand: [
                    'hospital_count' => 10,
                    'sum_beds' => 2345,
                    'avg_beds' => 234.5,
                    'percent_of_total' => 100.0,
                ]),
            ),
        );

        $csv = $this->exportToString($document);

        self::assertStringContainsString('Hospitals,"% of total","Total beds","% of total","Average beds"', $csv);
        self::assertStringContainsString("Full,10,38.46,2345,100,234.5\n", $csv);
        self::assertStringContainsString("Total,10,100,2345,100,234.5\n", $csv);
    }

    public function testMatrixLayoutExportsSeriesColumnsAndTotals(): void
    {
        $builder = $this->createBuilder();
        $viewConfig = $this->viewConfig(
            columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            tableLayout: TableLayout::Matrix,
        );

        $document = $builder->build(
            $viewConfig,
            new AnalysisRunResult(
                title: 'Gender matrix',
                metricKeys: [AnalysisMetricKey::AllocationCount],
                visualMetricKey: AnalysisMetricKey::AllocationCount,
                rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
                columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
                rows: [
                    new AnalysisResultRow('2024-06', 'Jun 2024', 'male', 'Male', ['allocation_count' => 4]),
                    new AnalysisResultRow('2024-06', 'Jun 2024', 'female', 'Female', ['allocation_count' => 6]),
                ],
                totals: new AnalysisTotals(
                    grand: ['allocation_count' => 10],
                    byColumn: [
                        'male' => ['allocation_count' => 4],
                        'female' => ['allocation_count' => 6],
                    ],
                ),
            ),
        );

        $csv = $this->exportToString($document);

        self::assertStringContainsString("Month,Male,Female,Total\n", $csv);
        self::assertStringContainsString('"Jun 2024",4,6,10'."\n", $csv);
        self::assertStringContainsString("Total,4,6,10\n", $csv);
    }

    public function testMatrixLayoutWithPercentExportsInterleavedColumns(): void
    {
        $builder = $this->createBuilder();
        $viewConfig = $this->viewConfig(
            columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            tableLayout: TableLayout::Matrix,
            metricKeys: [AnalysisMetricKey::AllocationCount, AnalysisMetricKey::PercentOfTotal],
        );

        $document = $builder->build(
            $viewConfig,
            new AnalysisRunResult(
                title: 'Gender matrix',
                metricKeys: [AnalysisMetricKey::AllocationCount, AnalysisMetricKey::PercentOfTotal],
                visualMetricKey: AnalysisMetricKey::AllocationCount,
                rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
                columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
                rows: [
                    new AnalysisResultRow('2024-06', 'Jun 2024', 'male', 'Male', ['allocation_count' => 4]),
                    new AnalysisResultRow('2024-06', 'Jun 2024', 'female', 'Female', ['allocation_count' => 6]),
                ],
                totals: new AnalysisTotals(
                    grand: ['allocation_count' => 10],
                    byColumn: [
                        'male' => ['allocation_count' => 4],
                        'female' => ['allocation_count' => 6],
                    ],
                ),
            ),
        );

        $csv = $this->exportToString($document);

        self::assertStringContainsString('Male,"Male %",Female,"Female %",Total,"Total % of total"', $csv);
        self::assertStringContainsString('"Jun 2024",4,40,6,60,10,100'."\n", $csv);
    }

    public function testMatrixMetricsAsRowsLayoutExportsMetricSubRows(): void
    {
        $builder = $this->createBuilder();
        $viewConfig = $this->viewConfig(
            columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            tableLayout: TableLayout::MatrixMetricsAsRows,
        );

        $document = $builder->build(
            $viewConfig,
            new AnalysisRunResult(
                title: 'Metrics as rows',
                metricKeys: [AnalysisMetricKey::AllocationCount, AnalysisMetricKey::PercentOfTotal],
                visualMetricKey: AnalysisMetricKey::AllocationCount,
                rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
                columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
                rows: [
                    new AnalysisResultRow('2024-06', 'Jun 2024', 'male', 'Male', [
                        'allocation_count' => 4,
                        'percent_of_total' => 40.0,
                    ]),
                    new AnalysisResultRow('2024-06', 'Jun 2024', 'female', 'Female', [
                        'allocation_count' => 6,
                        'percent_of_total' => 60.0,
                    ]),
                ],
                totals: new AnalysisTotals(grand: ['allocation_count' => 10, 'percent_of_total' => 100.0]),
            ),
        );

        $csv = $this->exportToString($document);

        self::assertStringContainsString("Month,Metric,Male,Female\n", $csv);
        self::assertStringContainsString('"Jun 2024",Allocations,4,6'."\n", $csv);
    }

    public function testMatrixLayoutWithRateMetricOmitsSummedTotals(): void
    {
        $builder = $this->createBuilder();
        $viewConfig = $this->viewConfig(
            columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            tableLayout: TableLayout::Matrix,
            metricKeys: [AnalysisMetricKey::ResusRate],
            visualMetricKey: AnalysisMetricKey::ResusRate,
            chartType: ChartPresentationType::GroupedBar,
        );

        $document = $builder->build(
            $viewConfig,
            new AnalysisRunResult(
                title: 'Resuscitation rate by gender over time',
                metricKeys: [AnalysisMetricKey::ResusRate],
                visualMetricKey: AnalysisMetricKey::ResusRate,
                rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
                columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
                rows: [
                    new AnalysisResultRow('2024-06', 'Jun 2024', 'male', 'Male', ['resus_rate' => 10.0]),
                    new AnalysisResultRow('2024-06', 'Jun 2024', 'female', 'Female', ['resus_rate' => 20.0]),
                ],
                totals: new AnalysisTotals(grand: ['resus_rate' => null]),
            ),
        );

        $csv = $this->exportToString($document);

        self::assertStringContainsString('"Jun 2024",10,20,'."\n", $csv);
        self::assertStringNotContainsString('"Jun 2024",10,20,30'."\n", $csv);
        self::assertStringContainsString('Total,,,'."\n", $csv);
    }

    public function testMatrixMetricsAsRowsLayoutWithPercentExportsInterleavedColumns(): void
    {
        $builder = $this->createBuilder();
        $viewConfig = $this->viewConfig(
            columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            tableLayout: TableLayout::MatrixMetricsAsRows,
            metricKeys: [AnalysisMetricKey::AllocationCount, AnalysisMetricKey::PercentOfTotal],
        );

        $document = $builder->build(
            $viewConfig,
            new AnalysisRunResult(
                title: 'Metrics as rows',
                metricKeys: [AnalysisMetricKey::AllocationCount, AnalysisMetricKey::PercentOfTotal],
                visualMetricKey: AnalysisMetricKey::AllocationCount,
                rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
                columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
                rows: [
                    new AnalysisResultRow('2024-06', 'Jun 2024', 'male', 'Male', [
                        'allocation_count' => 4,
                        'percent_of_total' => 40.0,
                    ]),
                    new AnalysisResultRow('2024-06', 'Jun 2024', 'female', 'Female', [
                        'allocation_count' => 6,
                        'percent_of_total' => 60.0,
                    ]),
                ],
                totals: new AnalysisTotals(grand: ['allocation_count' => 10, 'percent_of_total' => 100.0]),
            ),
        );

        $csv = $this->exportToString($document);

        self::assertStringContainsString('Metric,Male,"Male %",Female,"Female %"', $csv);
        self::assertStringContainsString('"Jun 2024",Allocations,4,40,6,60'."\n", $csv);
    }

    public function testDistributionExportIncludesSeriesColumnForTwoDimensionalResults(): void
    {
        $builder = $this->createBuilder();
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

        $document = $builder->build(
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
                ],
                totals: new AnalysisTotals(grand: ['transport_time_distribution' => 25.0]),
            ),
        );

        $csv = $this->exportToString($document);

        self::assertStringContainsString('Urgency,Gender,n,Min,P25,Median,P75,Max', $csv);
        self::assertStringContainsString("High,Male,4,10,15,25,35,45\n", $csv);
    }

    private function createBuilder(): ExplorerResultsTableExportBuilder
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnMap([
            ['stats.analysis_explorer.dimension.month', [], 'statistics', null, 'Month'],
            ['stats.analysis_explorer.dimension.gender', [], 'statistics', null, 'Gender'],
            ['stats.analysis_explorer.metric.allocation_count', [], 'statistics', null, 'Allocations'],
            ['stats.analysis_explorer.metric.hospital_count', [], 'statistics', null, 'Hospitals'],
            ['stats.analysis_explorer.metric.sum_beds', [], 'statistics', null, 'Total beds'],
            ['stats.analysis_explorer.metric.avg_beds', [], 'statistics', null, 'Average beds'],
            ['stats.analysis_explorer.metric.percent_of_total', [], 'statistics', null, 'Percent of total'],
            ['stats.analysis_explorer.dimension.hospital_tier', [], 'statistics', null, 'Hospital tier'],
            ['stats.analysis_explorer.table.metric', [], 'statistics', null, 'Metric'],
            ['stats.analysis_explorer.table.footer_total', [], 'statistics', null, 'Total'],
            ['stats.analysis_explorer.export.percent_of_total', [], 'statistics', null, '% of total'],
            ['stats.analysis_explorer.export.percent_of_row', [], 'statistics', null, '%'],
            ['stats.analysis_explorer.dimension.urgency', [], 'statistics', null, 'Urgency'],
            ['stats.analysis_explorer.box_plot.column.distribution_count', [], 'statistics', null, 'n'],
            ['stats.analysis_explorer.box_plot.column.distribution_min', [], 'statistics', null, 'Min'],
            ['stats.analysis_explorer.box_plot.column.distribution_p25', [], 'statistics', null, 'P25'],
            ['stats.analysis_explorer.box_plot.column.distribution_median', [], 'statistics', null, 'Median'],
            ['stats.analysis_explorer.box_plot.column.distribution_p75', [], 'statistics', null, 'P75'],
            ['stats.analysis_explorer.box_plot.column.distribution_max', [], 'statistics', null, 'Max'],
        ]);
        $metricRegistry = new MetricRegistry();
        $metricValueFormatter = new MetricValueFormatter($metricRegistry);

        return new ExplorerResultsTableExportBuilder(
            $translator,
            new ExplorerTablePercentHelper($metricRegistry, $metricValueFormatter),
            new ExplorerMetricSummabilityPolicy(),
            new ExplorerMetricProfileRegistry(),
        );
    }

    /**
     * @param list<AnalysisMetricKey> $metricKeys
     */
    private function viewConfig(
        ?AnalysisAxisRef $columnAxis,
        TableLayout $tableLayout = TableLayout::Flat,
        array $metricKeys = [AnalysisMetricKey::AllocationCount],
        ?AnalysisMetricKey $visualMetricKey = null,
        ChartPresentationType $chartType = ChartPresentationType::Bar,
    ): AnalysisViewConfig {
        $visualMetricKey ??= $metricKeys[0] ?? AnalysisMetricKey::AllocationCount;

        return new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: $metricKeys,
            visualMetricKey: $visualMetricKey,
            rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
            columnAxis: $columnAxis,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(
                chartType: $chartType,
                tableLayout: $tableLayout,
            ),
            title: 'Test',
        );
    }

    private function exportToString(object $document): string
    {
        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);

        new CsvTabularExporter()->export($document, $stream);

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        self::assertIsString($content);

        return $content;
    }
}
