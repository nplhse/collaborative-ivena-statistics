<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisTotals;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;

final class ExplorerResultsTablePresenterHospitalMetricsTest extends ExplorerResultsTablePresenterTestCase
{
    public function testCreateShowsHospitalCountPercentInFlatTable(): void
    {
        $translator = $this->stubExplorerTranslator([
            ['stats.analysis_explorer.dimension.hospital_master_cohort', [], 'statistics', null, 'Master cohort'],
            ['stats.analysis_explorer.metric.hospital_count', [], 'statistics', null, 'Hospitals'],
            ['stats.analysis_explorer.metric.sum_beds', [], 'statistics', null, 'Total beds'],
            ['stats.analysis_explorer.table.footer_total', [], 'statistics', null, 'Total'],
            ['stats.analysis_explorer.table.footer_average', [], 'statistics', null, 'Ø'],
            ['stats.analysis_explorer.table.footer_minimum', [], 'statistics', null, 'Min.'],
            ['stats.analysis_explorer.table.footer_maximum', [], 'statistics', null, 'Max.'],
        ]);

        $presenter = $this->createExplorerResultsTablePresenter($translator);
        $viewConfig = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Hospitals,
            metricKeys: [AnalysisMetricKey::HospitalCount, AnalysisMetricKey::PercentOfTotal],
            visualMetricKey: AnalysisMetricKey::HospitalCount,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalMasterCohort),
            columnAxis: null,
            statisticsFilter: $this->publicStatisticsFilter(),
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
            ['stats.analysis_explorer.dimension.hospital_tier', [], 'statistics', null, 'Hospital tier'],
            ['stats.analysis_explorer.metric.hospital_count', [], 'statistics', null, 'Hospitals'],
            ['stats.analysis_explorer.metric.sum_beds', [], 'statistics', null, 'Total beds'],
            ['stats.analysis_explorer.metric.avg_beds', [], 'statistics', null, 'Average beds'],
            ['stats.analysis_explorer.table.footer_total', [], 'statistics', null, 'Total'],
            ['stats.analysis_explorer.table.footer_average', [], 'statistics', null, 'Ø'],
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
            statisticsFilter: $this->publicStatisticsFilter(),
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
        self::assertSame(2345, $table->rows[0]->metricValues['sum_beds']);
        self::assertTrue($table->isClientSortable());
        self::assertArrayNotHasKey('avg_beds', $table->rows[0]->formattedMetricPercentValues);
        self::assertSame('100 %', $table->formattedTotalsPercentValues['hospital_count']);
        self::assertSame('100 %', $table->formattedTotalsPercentValues['sum_beds']);
        self::assertArrayNotHasKey('avg_beds', $table->formattedTotalsPercentValues);
    }

    public function testCreateShowsRowPercentForSummableMetricsInMatrixMetricsAsRowsTable(): void
    {
        $translator = $this->stubExplorerTranslator([
            ['stats.analysis_explorer.dimension.hospital_tier', [], 'statistics', null, 'Hospital tier'],
            ['stats.analysis_explorer.dimension.gender', [], 'statistics', null, 'Gender'],
            ['stats.analysis_explorer.metric.hospital_count', [], 'statistics', null, 'Hospitals'],
            ['stats.analysis_explorer.metric.sum_beds', [], 'statistics', null, 'Total beds'],
            ['stats.analysis_explorer.table.footer_total', [], 'statistics', null, 'Total'],
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
            statisticsFilter: $this->publicStatisticsFilter(),
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
        self::assertFalse($table->isClientSortable());
        $sumBedsRow = $table->rows[1];
        self::assertSame('Total beds', $sumBedsRow->metricSubRowLabel);
        self::assertSame('63,97 %', $sumBedsRow->formattedSeriesPercentValues['Male']);
        self::assertSame('36,03 %', $sumBedsRow->formattedSeriesPercentValues['Female']);
    }

    public function testCreateUsesMetricSpecificFooterLabelsForHospitalMetrics(): void
    {
        $translator = $this->stubExplorerTranslator([
            ['stats.analysis_explorer.dimension.hospital_tier', [], 'statistics', null, 'Hospital tier'],
            ['stats.analysis_explorer.metric.hospital_count', [], 'statistics', null, 'Hospitals'],
            ['stats.analysis_explorer.metric.sum_beds', [], 'statistics', null, 'Total beds'],
            ['stats.analysis_explorer.metric.avg_beds', [], 'statistics', null, 'Average beds'],
            ['stats.analysis_explorer.metric.min_beds', [], 'statistics', null, 'Minimum beds'],
            ['stats.analysis_explorer.metric.max_beds', [], 'statistics', null, 'Maximum beds'],
            ['stats.analysis_explorer.table.footer_total', [], 'statistics', null, 'Total'],
            ['stats.analysis_explorer.table.footer_average', [], 'statistics', null, 'Ø'],
            ['stats.analysis_explorer.table.footer_minimum', [], 'statistics', null, 'Min.'],
            ['stats.analysis_explorer.table.footer_maximum', [], 'statistics', null, 'Max.'],
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
            statisticsFilter: $this->publicStatisticsFilter(),
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

    public function testCreateShowsMultipleHospitalMetricsInFlatTable(): void
    {
        $translator = $this->stubExplorerTranslator([
            ['stats.analysis_explorer.dimension.hospital_tier', [], 'statistics', null, 'Hospital tier'],
            ['stats.analysis_explorer.metric.hospital_count', [], 'statistics', null, 'Hospitals'],
            ['stats.analysis_explorer.metric.avg_beds', [], 'statistics', null, 'Average beds'],
        ]);

        $presenter = $this->createExplorerResultsTablePresenter($translator);
        $viewConfig = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Hospitals,
            metricKeys: [AnalysisMetricKey::HospitalCount, AnalysisMetricKey::AvgBeds],
            visualMetricKey: AnalysisMetricKey::HospitalCount,
            rowAxis: AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalTier),
            columnAxis: null,
            statisticsFilter: $this->publicStatisticsFilter(),
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
}
