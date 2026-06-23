<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerResultsTableExportBuilder;
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
use App\Statistics\AnalysisExplorer\Domain\Enum\TableLayout;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\GenericAnalysis\Application\Export\CsvTabularExporter;
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
        self::assertStringContainsString('"Jun 2024","Percent of total",40,60'."\n", $csv);
    }

    private function createBuilder(): ExplorerResultsTableExportBuilder
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnMap([
            ['stats.analysis_explorer.dimension.month', [], null, null, 'Month'],
            ['stats.analysis_explorer.dimension.gender', [], null, null, 'Gender'],
            ['stats.analysis_explorer.metric.allocation_count', [], null, null, 'Allocations'],
            ['stats.analysis_explorer.metric.percent_of_total', [], null, null, 'Percent of total'],
            ['stats.analysis_explorer.table.metric', [], null, null, 'Metric'],
            ['stats.analysis_explorer.table.footer_total', [], null, null, 'Total'],
        ]);

        return new ExplorerResultsTableExportBuilder($translator);
    }

    private function viewConfig(
        ?AnalysisAxisRef $columnAxis,
        TableLayout $tableLayout = TableLayout::Flat,
    ): AnalysisViewConfig {
        return new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::AllocationCount],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
            columnAxis: $columnAxis,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(
                chartType: ChartPresentationType::Bar,
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
