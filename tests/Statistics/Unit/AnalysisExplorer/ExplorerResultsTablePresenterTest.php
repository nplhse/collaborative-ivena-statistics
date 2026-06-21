<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
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
            dimensionKey: AnalysisDimensionKey::Time,
            timeGrain: AnalysisDimensionGrain::Month,
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
                dimensionKey: AnalysisDimensionKey::Time,
                timeGrain: AnalysisDimensionGrain::Month,
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
                totals: ['allocation_count' => 20],
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

    public function testCreateBuildsPivotTableForSeriesResult(): void
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
            dimensionKey: AnalysisDimensionKey::Gender,
            timeGrain: AnalysisDimensionGrain::Month,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(chartType: ChartPresentationType::GroupedBar),
            title: 'Allocations by gender over time',
        );

        $table = $presenter->create(
            $viewConfig,
            new AnalysisRunResult(
                title: 'Allocations by gender over time',
                metricKeys: [AnalysisMetricKey::AllocationCount],
                visualMetricKey: AnalysisMetricKey::AllocationCount,
                dimensionKey: AnalysisDimensionKey::Gender,
                timeGrain: AnalysisDimensionGrain::Month,
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
                totals: ['allocation_count' => 6],
            ),
        );

        self::assertTrue($table->hasSeries);
        self::assertSame(['Male', 'Female'], $table->seriesLabels);
        self::assertCount(1, $table->rows);
        self::assertSame(6.0, $table->rows[0]->rowTotal);
        self::assertSame(4.0, $table->rows[0]->seriesValues['Male']);
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
            dimensionKey: AnalysisDimensionKey::Time,
            timeGrain: AnalysisDimensionGrain::Year,
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
                dimensionKey: AnalysisDimensionKey::Time,
                timeGrain: AnalysisDimensionGrain::Year,
                rows: [],
                totals: ['allocation_count' => 0],
            ),
        );

        self::assertFalse($table->hasData());
        self::assertSame('0', $table->formattedTotals['allocation_count']);
        self::assertCount(0, $table->rows);
    }
}
