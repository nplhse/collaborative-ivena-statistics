<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Application\DTO\AnalysisMatrix;
use App\Statistics\AnalysisExplorer\Application\DTO\ExplorerResultsTableMetricColumn;
use App\Statistics\AnalysisExplorer\Application\DTO\ExplorerResultsTableRow;
use App\Statistics\AnalysisExplorer\Application\DTO\ExplorerResultsTableViewModel;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\DTO\BoxPlotStats;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\BoxPlotTableColumn;
use App\Statistics\AnalysisExplorer\Domain\Enum\TableLayout;
use App\Statistics\GenericAnalysis\Application\MetricValueFormatter;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ExplorerResultsTablePresenter
{
    public function __construct(
        private TranslatorInterface $translator,
        private ExplorerMetricKeyMapper $metricKeyMapper,
        private MetricRegistry $metricRegistry,
        private MetricValueFormatter $metricValueFormatter,
        private ExplorerTablePercentHelper $percentHelper,
        private ExplorerMetricSummabilityPolicy $summabilityPolicy,
        private ExplorerMetricProfileRegistry $profileRegistry,
    ) {
    }

    public function create(AnalysisViewConfig $viewConfig, AnalysisRunResult $result): ExplorerResultsTableViewModel
    {
        if ($viewConfig->visualMetricKey->isDistributionProfile()) {
            return $this->createDistributionFlatTable($viewConfig, $result);
        }

        if (!$result->hasColumnAxis()) {
            return $this->createFlatTable($viewConfig, $result);
        }

        return match ($viewConfig->presentation->tableLayout) {
            TableLayout::MatrixMetricsAsRows => $this->createMatrixMetricsAsRowsTable($viewConfig, $result),
            default => $this->createMatrixTable($viewConfig, $result),
        };
    }

    private function createFlatTable(AnalysisViewConfig $viewConfig, AnalysisRunResult $result): ExplorerResultsTableViewModel
    {
        $showPercentOfTotal = $viewConfig->showsPercentOfTotal();
        $visibleMetricKeys = $this->visibleMetricKeys($result->metricKeys, $showPercentOfTotal);
        $metricColumns = $this->metricColumns($visibleMetricKeys);
        $rows = [];

        foreach ($result->rows as $row) {
            $formattedMetricPercentValues = [];
            if ($showPercentOfTotal) {
                foreach ($visibleMetricKeys as $metricKey) {
                    if (!$this->summabilityPolicy->supportsPercentShare($metricKey)) {
                        continue;
                    }

                    $formattedMetricPercentValues[$metricKey->value] = $this->percentHelper->formatPercent(
                        $this->resolveFlatPercentShare($row, $metricKey, $result),
                    );
                }
            }

            $rows[] = new ExplorerResultsTableRow(
                bucketLabel: $row->bucketLabel,
                formattedMetricValues: $this->formatRowValues($visibleMetricKeys, $row->metricValues),
                formattedMetricPercentValues: $formattedMetricPercentValues,
            );
        }

        $formattedTotalsPercentValues = [];
        if ($showPercentOfTotal) {
            foreach ($visibleMetricKeys as $metricKey) {
                if (!$this->summabilityPolicy->supportsPercentShare($metricKey)) {
                    continue;
                }

                $formattedTotalsPercentValues[$metricKey->value] = $this->percentHelper->formatPercent(100.0);
            }
        }

        return new ExplorerResultsTableViewModel(
            primaryDimensionLabel: $this->axisLabel($viewConfig->rowAxis),
            metricColumns: $metricColumns,
            rows: $rows,
            formattedTotals: $this->formatTotals($result, $visibleMetricKeys),
            tableLayout: TableLayout::Flat,
            rowAxisLabel: $this->axisLabel($viewConfig->rowAxis),
            showPercentOfTotal: $showPercentOfTotal,
            formattedTotalsPercentValues: $formattedTotalsPercentValues,
            footerRowLabel: $this->footerRowLabel($visibleMetricKeys),
        );
    }

    private function createDistributionFlatTable(AnalysisViewConfig $viewConfig, AnalysisRunResult $result): ExplorerResultsTableViewModel
    {
        $tableColumns = $this->profileRegistry->tableColumnsFor($viewConfig->visualMetricKey);
        $hasSeries = $result->hasSeries();
        $metricColumns = [];
        if ($hasSeries) {
            $metricColumns[] = new ExplorerResultsTableMetricColumn(
                key: 'series',
                label: $this->distributionSeriesAxisLabel($viewConfig, $result),
            );
        }
        foreach ($tableColumns as $column) {
            $metricColumns[] = new ExplorerResultsTableMetricColumn(
                key: $column->value,
                label: $this->translator->trans($column->labelTranslationKey()),
            );
        }

        $rows = [];
        foreach ($result->rows as $row) {
            $formattedMetricValues = $this->formatBoxPlotRowValues($tableColumns, $row->boxPlot, $viewConfig->visualMetricKey);
            if ($hasSeries) {
                $formattedMetricValues = ['series' => $row->seriesLabel ?? '—'] + $formattedMetricValues;
            }

            $rows[] = new ExplorerResultsTableRow(
                bucketLabel: $row->bucketLabel,
                formattedMetricValues: $formattedMetricValues,
            );
        }

        return new ExplorerResultsTableViewModel(
            primaryDimensionLabel: $this->axisLabel($viewConfig->rowAxis),
            metricColumns: $metricColumns,
            rows: $rows,
            formattedTotals: [],
            tableLayout: TableLayout::Flat,
            rowAxisLabel: $this->axisLabel($viewConfig->rowAxis),
            columnAxisLabel: $hasSeries ? $this->distributionSeriesAxisLabel($viewConfig, $result) : '',
            showPercentOfTotal: false,
            formattedTotalsPercentValues: [],
        );
    }

    private function distributionSeriesAxisLabel(AnalysisViewConfig $viewConfig, AnalysisRunResult $result): string
    {
        if ($viewConfig->columnAxis instanceof AnalysisAxisRef) {
            return $this->axisLabel($viewConfig->columnAxis);
        }

        if ($result->columnAxis instanceof AnalysisAxisRef) {
            return $this->axisLabel($result->columnAxis);
        }

        return $this->translator->trans('stats.analysis_explorer.edit.hospital_population');
    }

    /**
     * @param list<BoxPlotTableColumn> $tableColumns
     *
     * @return array<string, string>
     */
    private function formatBoxPlotRowValues(array $tableColumns, ?BoxPlotStats $boxPlot, AnalysisMetricKey $visualMetricKey): array
    {
        $formatted = [];
        foreach ($tableColumns as $column) {
            $formatted[$column->value] = $this->formatBoxPlotColumnValue($column, $boxPlot, $visualMetricKey);
        }

        return $formatted;
    }

    private function formatBoxPlotColumnValue(BoxPlotTableColumn $column, ?BoxPlotStats $boxPlot, AnalysisMetricKey $visualMetricKey): string
    {
        if (!$boxPlot instanceof BoxPlotStats) {
            return '—';
        }

        $value = match ($column) {
            BoxPlotTableColumn::Count => $boxPlot->count,
            BoxPlotTableColumn::Min => $boxPlot->minimum,
            BoxPlotTableColumn::P25 => $boxPlot->p25,
            BoxPlotTableColumn::Median => $boxPlot->median,
            BoxPlotTableColumn::P75 => $boxPlot->p75,
            BoxPlotTableColumn::Max => $boxPlot->maximum,
        };

        if (BoxPlotTableColumn::Count === $column) {
            return (string) $value;
        }

        return $this->metricValueFormatter->format(
            $this->metricRegistry->get($this->profileRegistry->formatRegistryKeyFor($visualMetricKey)),
            $value,
        );
    }

    private function createMatrixTable(AnalysisViewConfig $viewConfig, AnalysisRunResult $result): ExplorerResultsTableViewModel
    {
        $visualMetricKey = $result->visualMetricKey;
        $summable = $this->summabilityPolicy->isSummable($visualMetricKey);
        $showPercentOfTotal = $viewConfig->showsPercentOfTotal() && $summable;
        $matrix = AnalysisMatrix::fromRunResult($result);
        $metricColumns = $this->metricColumns([$visualMetricKey]);
        $columnLabels = array_values($matrix->columnLabels);
        $grandTotal = $summable ? $result->totalFor($visualMetricKey) : null;
        $rows = [];
        $columnTotals = [];

        foreach ($matrix->orderedRowKeys as $rowKey) {
            $seriesValues = [];
            $formattedSeriesValues = [];
            $formattedSeriesPercentValues = [];
            $rowTotal = 0.0;

            foreach ($matrix->orderedColumnKeys as $colKey) {
                $value = $matrix->valueFor($rowKey, $colKey, $visualMetricKey);
                $label = $matrix->columnLabels[$colKey];
                $seriesValues[$label] = $value;
                $formattedSeriesValues[$label] = $this->formatMetricValue($visualMetricKey, $value);
                if ($summable) {
                    $rowTotal += $value;
                    $columnTotals[$label] = ($columnTotals[$label] ?? 0.0) + $value;
                }
            }

            if ($showPercentOfTotal) {
                foreach ($matrix->orderedColumnKeys as $colKey) {
                    $label = $matrix->columnLabels[$colKey];
                    $value = $seriesValues[$label];
                    $formattedSeriesPercentValues[$label] = $this->percentHelper->formatPercent(
                        $this->percentHelper->percentOfRow($value, $rowTotal),
                    );
                }
            }

            $rows[] = new ExplorerResultsTableRow(
                bucketLabel: $matrix->rowLabels[$rowKey],
                formattedMetricValues: [
                    $visualMetricKey->value => $this->formatMetricValue($visualMetricKey, $summable ? $rowTotal : null),
                ],
                seriesValues: $seriesValues,
                formattedSeriesValues: $formattedSeriesValues,
                formattedRowTotal: $this->formatMetricValue($visualMetricKey, $summable ? $rowTotal : null),
                rowTotal: $summable ? $rowTotal : 0.0,
                formattedSeriesPercentValues: $formattedSeriesPercentValues,
                formattedRowTotalPercent: $showPercentOfTotal
                    ? $this->percentHelper->formatPercent($this->percentHelper->percentOfTotal($rowTotal, (float) $grandTotal))
                    : '',
            );
        }

        $formattedSeriesTotals = [];
        $formattedSeriesFooterPercentValues = [];
        foreach ($columnLabels as $seriesLabel) {
            $columnTotal = $summable ? (float) ($columnTotals[$seriesLabel] ?? 0) : null;
            $formattedSeriesTotals[$seriesLabel] = $this->formatMetricValue(
                $visualMetricKey,
                $columnTotal,
            );
            if ($showPercentOfTotal) {
                $formattedSeriesFooterPercentValues[$seriesLabel] = $this->percentHelper->formatPercent(
                    $this->percentHelper->percentOfTotal($columnTotal, (float) $grandTotal),
                );
            }
        }

        return new ExplorerResultsTableViewModel(
            primaryDimensionLabel: $this->axisLabel($viewConfig->rowAxis),
            metricColumns: $metricColumns,
            rows: $rows,
            formattedTotals: $this->formatTotals($result, [$visualMetricKey]),
            hasSeries: true,
            seriesLabels: $columnLabels,
            formattedSeriesTotals: $formattedSeriesTotals,
            formattedGrandTotal: $this->formatMetricValue($visualMetricKey, $grandTotal),
            tableLayout: TableLayout::Matrix,
            rowAxisLabel: $this->axisLabel($viewConfig->rowAxis),
            columnAxisLabel: $viewConfig->columnAxis instanceof AnalysisAxisRef
                ? $this->axisLabel($viewConfig->columnAxis)
                : '',
            showPercentOfTotal: $showPercentOfTotal,
            formattedSeriesFooterPercentValues: $formattedSeriesFooterPercentValues,
            formattedGrandTotalPercent: $showPercentOfTotal
                ? $this->percentHelper->formatPercent(100.0)
                : '',
        );
    }

    private function createMatrixMetricsAsRowsTable(AnalysisViewConfig $viewConfig, AnalysisRunResult $result): ExplorerResultsTableViewModel
    {
        $showPercentOfTotal = $viewConfig->showsPercentOfTotal();
        $matrix = AnalysisMatrix::fromRunResult($result);
        $columnLabels = array_values($matrix->columnLabels);
        $metricKeys = $this->visibleMetricKeys($result->metricKeys, $showPercentOfTotal);
        $rows = [];

        foreach ($matrix->orderedRowKeys as $rowKey) {
            foreach ($metricKeys as $metricKey) {
                $seriesValues = [];
                $formattedSeriesValues = [];
                $formattedSeriesPercentValues = [];
                $rowTotal = 0.0;

                foreach ($matrix->orderedColumnKeys as $colKey) {
                    $rowTotal += $matrix->valueFor($rowKey, $colKey, $metricKey);
                }

                foreach ($matrix->orderedColumnKeys as $colKey) {
                    $label = $matrix->columnLabels[$colKey];
                    $value = $matrix->valueFor($rowKey, $colKey, $metricKey);
                    $seriesValues[$label] = $value;
                    $formattedSeriesValues[$label] = $this->formatMetricValue($metricKey, $value);
                    if ($showPercentOfTotal && $this->summabilityPolicy->supportsPercentShare($metricKey)) {
                        $formattedSeriesPercentValues[$label] = $this->percentHelper->formatPercent(
                            $this->percentHelper->percentOfRow($value, $rowTotal),
                        );
                    }
                }

                $rows[] = new ExplorerResultsTableRow(
                    bucketLabel: $matrix->rowLabels[$rowKey],
                    formattedMetricValues: [],
                    seriesValues: $seriesValues,
                    formattedSeriesValues: $formattedSeriesValues,
                    metricSubRowLabel: $this->metricLabel($metricKey),
                    formattedSeriesPercentValues: $formattedSeriesPercentValues,
                );
            }
        }

        return new ExplorerResultsTableViewModel(
            primaryDimensionLabel: $this->axisLabel($viewConfig->rowAxis),
            metricColumns: [],
            rows: $rows,
            formattedTotals: $this->formatTotals($result, $metricKeys),
            hasSeries: true,
            seriesLabels: $columnLabels,
            tableLayout: TableLayout::MatrixMetricsAsRows,
            rowAxisLabel: $this->axisLabel($viewConfig->rowAxis),
            columnAxisLabel: $viewConfig->columnAxis instanceof AnalysisAxisRef
                ? $this->axisLabel($viewConfig->columnAxis)
                : '',
            hasMetricSubRows: true,
            showPercentOfTotal: $showPercentOfTotal,
        );
    }

    /**
     * @param list<AnalysisMetricKey> $metricKeys
     *
     * @return list<AnalysisMetricKey>
     */
    private function visibleMetricKeys(array $metricKeys, bool $showPercentOfTotal): array
    {
        if (!$showPercentOfTotal) {
            return $metricKeys;
        }

        return array_values(array_filter(
            $metricKeys,
            static fn (AnalysisMetricKey $metricKey): bool => AnalysisMetricKey::PercentOfTotal !== $metricKey,
        ));
    }

    /**
     * @param list<AnalysisMetricKey> $metricKeys
     *
     * @return list<ExplorerResultsTableMetricColumn>
     */
    private function metricColumns(array $metricKeys): array
    {
        $columns = [];
        foreach ($metricKeys as $metricKey) {
            $columns[] = new ExplorerResultsTableMetricColumn(
                key: $metricKey->value,
                label: $this->metricLabel($metricKey),
                footerLabel: $this->footerLabelForMetric($metricKey),
            );
        }

        return $columns;
    }

    /**
     * @param list<AnalysisMetricKey>       $metricKeys
     * @param array<string, int|float|null> $values
     *
     * @return array<string, string>
     */
    private function formatRowValues(array $metricKeys, array $values): array
    {
        $formatted = [];
        foreach ($metricKeys as $metricKey) {
            $formatted[$metricKey->value] = $this->formatMetricValue(
                $metricKey,
                $values[$metricKey->value] ?? null,
            );
        }

        return $formatted;
    }

    /**
     * @param list<AnalysisMetricKey> $visibleMetricKeys
     *
     * @return array<string, string>
     */
    private function formatTotals(AnalysisRunResult $result, array $visibleMetricKeys): array
    {
        $formatted = [];
        foreach ($visibleMetricKeys as $metricKey) {
            $formatted[$metricKey->value] = $this->formatMetricValue(
                $metricKey,
                $result->totalFor($metricKey),
            );
        }

        return $formatted;
    }

    private function formatMetricValue(AnalysisMetricKey $metricKey, int|float|null $value): string
    {
        $registryKey = $this->metricKeyMapper->toRegistryKey($metricKey);

        return $this->metricValueFormatter->format(
            $this->metricRegistry->get($registryKey),
            $value,
        );
    }

    private function axisLabel(AnalysisAxisRef $axis): string
    {
        if ($axis->dimensionKey->isTemporalPrimary()) {
            return $this->temporalDimensionLabel($axis->resolvedGrain());
        }

        return $this->translator->trans('stats.analysis_explorer.dimension.'.$axis->dimensionKey->value);
    }

    private function temporalDimensionLabel(AnalysisDimensionGrain $grain): string
    {
        return match ($grain) {
            AnalysisDimensionGrain::Year => $this->translator->trans('stats.analysis_explorer.dimension.year'),
            AnalysisDimensionGrain::Quarter => $this->translator->trans('stats.analysis_explorer.dimension.quarter'),
            AnalysisDimensionGrain::Week => $this->translator->trans('stats.analysis_explorer.dimension.week'),
            AnalysisDimensionGrain::Total => $this->translator->trans('stats.analysis_explorer.grain.total'),
            default => $this->translator->trans('stats.analysis_explorer.dimension.month'),
        };
    }

    private function metricLabel(AnalysisMetricKey $metricKey): string
    {
        $profile = $this->profileRegistry->profileFor($metricKey);
        if ($profile instanceof \App\Statistics\AnalysisExplorer\Domain\DTO\ExplorerMetricProfileDefinition) {
            return $this->translator->trans($profile->labelTranslationKey);
        }

        return $this->translator->trans('stats.analysis_explorer.metric.'.$metricKey->value);
    }

    private function countMetricForPercent(AnalysisRunResult $result): ?AnalysisMetricKey
    {
        foreach ([AnalysisMetricKey::AllocationCount, AnalysisMetricKey::HospitalCount] as $metricKey) {
            if (\in_array($metricKey, $result->metricKeys, true)) {
                return $metricKey;
            }
        }

        return null;
    }

    private function resolveFlatPercentShare(
        AnalysisResultRow $row,
        AnalysisMetricKey $metricKey,
        AnalysisRunResult $result,
    ): ?float {
        $countMetric = $this->countMetricForPercent($result);
        if ($metricKey === $countMetric) {
            $sqlPercent = $row->valueFor(AnalysisMetricKey::PercentOfTotal);
            if (null !== $sqlPercent) {
                return (float) $sqlPercent;
            }
        }

        return $this->percentHelper->percentOfTotal(
            $row->valueFor($metricKey),
            $result->totalFor($metricKey),
        );
    }

    /**
     * @param list<AnalysisMetricKey> $visibleMetricKeys
     */
    private function footerRowLabel(array $visibleMetricKeys): string
    {
        if (1 !== \count($visibleMetricKeys)) {
            return '';
        }

        return $this->footerLabelForMetric($visibleMetricKeys[0]);
    }

    private function footerLabelForMetric(AnalysisMetricKey $metricKey): string
    {
        return match ($metricKey) {
            AnalysisMetricKey::AvgBeds,
            AnalysisMetricKey::AvgAllocationsPerHospital => $this->translator->trans('stats.analysis_explorer.table.footer_average'),
            AnalysisMetricKey::MinBeds,
            AnalysisMetricKey::MinAllocations => $this->translator->trans('stats.analysis_explorer.table.footer_minimum'),
            AnalysisMetricKey::MaxBeds,
            AnalysisMetricKey::MaxAllocations => $this->translator->trans('stats.analysis_explorer.table.footer_maximum'),
            default => $this->translator->trans('stats.analysis_explorer.table.footer_total'),
        };
    }
}
