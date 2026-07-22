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

final class ExplorerResultsTablePresenterColumnsTest extends ExplorerResultsTablePresenterTestCase
{
    public function testCreateBuildsRowsAndTotalFromResult(): void
    {
        $translator = $this->stubExplorerTranslator([
            ['stats.analysis_explorer.dimension.month', [], 'statistics', null, 'Month'],
            ['stats.analysis_explorer.metric.allocation_count', [], 'statistics', null, 'Allocations'],
        ]);

        $presenter = $this->createExplorerResultsTablePresenter($translator);
        $viewConfig = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::AllocationCount],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
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
        $translator = $this->stubExplorerTranslator([
            ['stats.analysis_explorer.dimension.gender', [], 'statistics', null, 'Gender'],
            ['stats.analysis_explorer.metric.allocation_count', [], 'statistics', null, 'Allocations'],
        ]);

        $presenter = $this->createExplorerResultsTablePresenter($translator);
        $viewConfig = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::AllocationCount, AnalysisMetricKey::PercentOfTotal],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            columnAxis: null,
            statisticsFilter: $this->publicStatisticsFilter(),
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
}
