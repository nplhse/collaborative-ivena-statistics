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

final class ExplorerResultsTablePresenterTest extends TestCase
{
    use AnalysisExplorerTestSupport;

    public function testCreateBuildsRowsAndTotalFromResult(): void
    {
        $translator = $this->stubExplorerTranslator([
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
        $translator = $this->stubExplorerTranslator([
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

    public function testCreateShowsHospitalCountPercentInFlatTable(): void
    {
        $translator = $this->stubExplorerTranslator([
            ['stats.analysis_explorer.dimension.hospital_master_cohort', [], null, null, 'Master cohort'],
            ['stats.analysis_explorer.metric.hospital_count', [], null, null, 'Hospitals'],
            ['stats.analysis_explorer.metric.sum_beds', [], null, null, 'Total beds'],
            ['stats.analysis_explorer.table.footer_total', [], null, null, 'Total'],
            ['stats.analysis_explorer.table.footer_average', [], null, null, 'Ø'],
            ['stats.analysis_explorer.table.footer_minimum', [], null, null, 'Min.'],
            ['stats.analysis_explorer.table.footer_maximum', [], null, null, 'Max.'],
        ]);

        $presenter = $this->createExplorerResultsTablePresenter($translator);
        $viewConfig = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Hospitals,
            metricKeys: [AnalysisMetricKey::HospitalCount, AnalysisMetricKey::PercentOfTotal],
            visualMetricKey: AnalysisMetricKey::HospitalCount,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalMasterCohort),
            columnAxis: null,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(chartType: ChartPresentationType::Bar),
            title: 'Hospitals by cohort',
        );

        $table = $presenter->create(
            $viewConfig,
            new AnalysisRunResult(
                title: 'Hospitals by cohort',
                metricKeys: [AnalysisMetricKey::HospitalCount, AnalysisMetricKey::PercentOfTotal],
                visualMetricKey: AnalysisMetricKey::HospitalCount,
                rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalMasterCohort),
                columnAxis: null,
                rows: [
                    new AnalysisResultRow('a', 'Cohort A', null, null, [
                        'hospital_count' => 10,
                        'percent_of_total' => 40.0,
                    ]),
                    new AnalysisResultRow('b', 'Cohort B', null, null, [
                        'hospital_count' => 15,
                        'percent_of_total' => 60.0,
                    ]),
                ],
                totals: new AnalysisTotals(grand: [
                    'hospital_count' => 25,
                    'percent_of_total' => 100.0,
                ]),
            ),
        );

        self::assertTrue($table->showPercentOfTotal);
        self::assertSame('40 %', $table->rows[0]->formattedMetricPercentValues['hospital_count']);
        self::assertSame('100 %', $table->formattedTotalsPercentValues['hospital_count']);
    }

    public function testCreateShowsPercentForAllSummableMetricsInFlatTable(): void
    {
        $translator = $this->stubExplorerTranslator([
            ['stats.analysis_explorer.dimension.hospital_tier', [], null, null, 'Hospital tier'],
            ['stats.analysis_explorer.metric.hospital_count', [], null, null, 'Hospitals'],
            ['stats.analysis_explorer.metric.sum_beds', [], null, null, 'Total beds'],
            ['stats.analysis_explorer.metric.avg_beds', [], null, null, 'Average beds'],
            ['stats.analysis_explorer.table.footer_total', [], null, null, 'Total'],
            ['stats.analysis_explorer.table.footer_average', [], null, null, 'Ø'],
        ]);

        $presenter = $this->createExplorerResultsTablePresenter($translator);
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

        $table = $presenter->create(
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
                    new AnalysisResultRow('basic', 'Basic', null, null, [
                        'hospital_count' => 16,
                        'sum_beds' => 10479,
                        'avg_beds' => 654.94,
                        'percent_of_total' => 61.54,
                    ]),
                ],
                totals: new AnalysisTotals(grand: [
                    'hospital_count' => 26,
                    'sum_beds' => 12824,
                    'avg_beds' => 493.23,
                    'percent_of_total' => 100.0,
                ]),
            ),
        );

        self::assertTrue($table->showPercentOfTotal);
        self::assertSame('38,46 %', $table->rows[0]->formattedMetricPercentValues['hospital_count']);
        self::assertSame('18,29 %', $table->rows[0]->formattedMetricPercentValues['sum_beds']);
        self::assertArrayNotHasKey('avg_beds', $table->rows[0]->formattedMetricPercentValues);
        self::assertSame('100 %', $table->formattedTotalsPercentValues['hospital_count']);
        self::assertSame('100 %', $table->formattedTotalsPercentValues['sum_beds']);
        self::assertArrayNotHasKey('avg_beds', $table->formattedTotalsPercentValues);
    }

    public function testCreateShowsRowPercentForSummableMetricsInMatrixMetricsAsRowsTable(): void
    {
        $translator = $this->stubExplorerTranslator([
            ['stats.analysis_explorer.dimension.hospital_tier', [], null, null, 'Hospital tier'],
            ['stats.analysis_explorer.dimension.gender', [], null, null, 'Gender'],
            ['stats.analysis_explorer.metric.hospital_count', [], null, null, 'Hospitals'],
            ['stats.analysis_explorer.metric.sum_beds', [], null, null, 'Total beds'],
            ['stats.analysis_explorer.table.footer_total', [], null, null, 'Total'],
        ]);

        $presenter = $this->createExplorerResultsTablePresenter($translator);
        $viewConfig = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Hospitals,
            metricKeys: [
                AnalysisMetricKey::HospitalCount,
                AnalysisMetricKey::SumBeds,
                AnalysisMetricKey::PercentOfTotal,
            ],
            visualMetricKey: AnalysisMetricKey::HospitalCount,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalTier),
            columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(
                chartType: ChartPresentationType::GroupedBar,
                tableLayout: \App\Statistics\AnalysisExplorer\Domain\Enum\TableLayout::MatrixMetricsAsRows,
            ),
            title: 'Hospitals by tier and gender',
        );

        $table = $presenter->create(
            $viewConfig,
            new AnalysisRunResult(
                title: 'Hospitals by tier and gender',
                metricKeys: [
                    AnalysisMetricKey::HospitalCount,
                    AnalysisMetricKey::SumBeds,
                    AnalysisMetricKey::PercentOfTotal,
                ],
                visualMetricKey: AnalysisMetricKey::HospitalCount,
                rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalTier),
                columnAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
                rows: [
                    new AnalysisResultRow('full', 'Full', 'male', 'Male', [
                        'hospital_count' => 6,
                        'sum_beds' => 1500,
                    ]),
                    new AnalysisResultRow('full', 'Full', 'female', 'Female', [
                        'hospital_count' => 4,
                        'sum_beds' => 845,
                    ]),
                ],
                totals: new AnalysisTotals(grand: [
                    'hospital_count' => 10,
                    'sum_beds' => 2345,
                ]),
            ),
        );

        self::assertTrue($table->showPercentOfTotal);
        self::assertTrue($table->hasMetricSubRows);
        $sumBedsRow = $table->rows[1];
        self::assertSame('Total beds', $sumBedsRow->metricSubRowLabel);
        self::assertSame('63,97 %', $sumBedsRow->formattedSeriesPercentValues['Male']);
        self::assertSame('36,03 %', $sumBedsRow->formattedSeriesPercentValues['Female']);
    }

    public function testCreateUsesMetricSpecificFooterLabelsForHospitalMetrics(): void
    {
        $translator = $this->stubExplorerTranslator([
            ['stats.analysis_explorer.dimension.hospital_tier', [], null, null, 'Hospital tier'],
            ['stats.analysis_explorer.metric.hospital_count', [], null, null, 'Hospitals'],
            ['stats.analysis_explorer.metric.sum_beds', [], null, null, 'Total beds'],
            ['stats.analysis_explorer.metric.avg_beds', [], null, null, 'Average beds'],
            ['stats.analysis_explorer.metric.min_beds', [], null, null, 'Minimum beds'],
            ['stats.analysis_explorer.metric.max_beds', [], null, null, 'Maximum beds'],
            ['stats.analysis_explorer.table.footer_total', [], null, null, 'Total'],
            ['stats.analysis_explorer.table.footer_average', [], null, null, 'Ø'],
            ['stats.analysis_explorer.table.footer_minimum', [], null, null, 'Min.'],
            ['stats.analysis_explorer.table.footer_maximum', [], null, null, 'Max.'],
        ]);

        $presenter = $this->createExplorerResultsTablePresenter($translator);
        $viewConfig = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Hospitals,
            metricKeys: [
                AnalysisMetricKey::HospitalCount,
                AnalysisMetricKey::SumBeds,
                AnalysisMetricKey::AvgBeds,
                AnalysisMetricKey::MinBeds,
                AnalysisMetricKey::MaxBeds,
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

        $table = $presenter->create(
            $viewConfig,
            new AnalysisRunResult(
                title: 'Hospitals by tier',
                metricKeys: [
                    AnalysisMetricKey::HospitalCount,
                    AnalysisMetricKey::SumBeds,
                    AnalysisMetricKey::AvgBeds,
                    AnalysisMetricKey::MinBeds,
                    AnalysisMetricKey::MaxBeds,
                ],
                visualMetricKey: AnalysisMetricKey::HospitalCount,
                rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalTier),
                columnAxis: null,
                rows: [
                    new AnalysisResultRow('full', 'Full', null, null, [
                        'hospital_count' => 10,
                        'sum_beds' => 1000,
                        'avg_beds' => 100.0,
                        'min_beds' => 20.0,
                        'max_beds' => 80.0,
                    ]),
                    new AnalysisResultRow('basic', 'Basic', null, null, [
                        'hospital_count' => 16,
                        'sum_beds' => 3200,
                        'avg_beds' => 200.0,
                        'min_beds' => 10.0,
                        'max_beds' => 120.0,
                    ]),
                ],
                totals: new AnalysisTotals(grand: [
                    'hospital_count' => 26,
                    'sum_beds' => 4200,
                    'avg_beds' => 161.538,
                    'min_beds' => 10,
                    'max_beds' => 120,
                ]),
            ),
        );

        self::assertSame('', $table->footerRowLabel);
        self::assertSame('Total', $table->metricColumns[0]->footerLabel);
        self::assertSame('Ø', $table->metricColumns[2]->footerLabel);
        self::assertSame('26', $table->formattedTotals['hospital_count']);
        self::assertSame('161,5', $table->formattedTotals['avg_beds']);
    }

    public function testCreateShowsRowPercentInMatrixTable(): void
    {
        $translator = $this->stubExplorerTranslator([
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
        $translator = $this->stubExplorerTranslator([
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
        $translator = $this->stubExplorerTranslator([
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
        $translator = $this->stubExplorerTranslator([
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
        $translator = $this->stubExplorerTranslator([
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
        $translator = $this->stubExplorerTranslator([
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
        $translator = $this->stubExplorerTranslator([
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
        $translator = $this->stubExplorerTranslator([
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
