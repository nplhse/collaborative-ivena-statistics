<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Application\DTO\AnalysisMatrix;
use App\Statistics\AnalysisExplorer\Application\DTO\ExplorerResultsTableMetricColumn;
use App\Statistics\AnalysisExplorer\Application\DTO\ExplorerResultsTableRow;
use App\Statistics\AnalysisExplorer\Application\DTO\ExplorerResultsTableViewModel;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
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
    ) {
    }

    public function create(AnalysisViewConfig $viewConfig, AnalysisRunResult $result): ExplorerResultsTableViewModel
    {
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
        $metricColumns = $this->metricColumns($result->metricKeys);
        $rows = [];
        foreach ($result->rows as $row) {
            $rows[] = new ExplorerResultsTableRow(
                bucketLabel: $row->bucketLabel,
                formattedMetricValues: $this->formatRowValues($result->metricKeys, $row->metricValues),
            );
        }

        return new ExplorerResultsTableViewModel(
            primaryDimensionLabel: $this->axisLabel($viewConfig->rowAxis),
            metricColumns: $metricColumns,
            rows: $rows,
            formattedTotals: $this->formatTotals($result),
            tableLayout: TableLayout::Flat,
            rowAxisLabel: $this->axisLabel($viewConfig->rowAxis),
        );
    }

    private function createMatrixTable(AnalysisViewConfig $viewConfig, AnalysisRunResult $result): ExplorerResultsTableViewModel
    {
        $matrix = AnalysisMatrix::fromRunResult($result);
        $metricColumns = $this->metricColumns([$result->visualMetricKey]);
        $columnLabels = array_values($matrix->columnLabels);
        $rows = [];
        $columnTotals = [];

        foreach ($matrix->orderedRowKeys as $rowKey) {
            $seriesValues = [];
            $formattedSeriesValues = [];
            $rowTotal = 0.0;

            foreach ($matrix->orderedColumnKeys as $colKey) {
                $value = $matrix->valueFor($rowKey, $colKey, $result->visualMetricKey);
                $label = $matrix->columnLabels[$colKey];
                $seriesValues[$label] = $value;
                $formattedSeriesValues[$label] = $this->formatMetricValue($result->visualMetricKey, $value);
                $rowTotal += $value;
                $columnTotals[$label] = ($columnTotals[$label] ?? 0.0) + $value;
            }

            $rows[] = new ExplorerResultsTableRow(
                bucketLabel: $matrix->rowLabels[$rowKey],
                formattedMetricValues: [
                    $result->visualMetricKey->value => $this->formatMetricValue($result->visualMetricKey, $rowTotal),
                ],
                seriesValues: $seriesValues,
                formattedSeriesValues: $formattedSeriesValues,
                formattedRowTotal: $this->formatMetricValue($result->visualMetricKey, $rowTotal),
                rowTotal: $rowTotal,
            );
        }

        $formattedSeriesTotals = [];
        foreach ($columnLabels as $seriesLabel) {
            $formattedSeriesTotals[$seriesLabel] = $this->formatMetricValue(
                $result->visualMetricKey,
                (float) ($columnTotals[$seriesLabel] ?? 0),
            );
        }

        return new ExplorerResultsTableViewModel(
            primaryDimensionLabel: $this->axisLabel($viewConfig->rowAxis),
            metricColumns: $metricColumns,
            rows: $rows,
            formattedTotals: $this->formatTotals($result),
            hasSeries: true,
            seriesLabels: $columnLabels,
            formattedSeriesTotals: $formattedSeriesTotals,
            formattedGrandTotal: $this->formatMetricValue($result->visualMetricKey, $result->primaryTotal()),
            tableLayout: TableLayout::Matrix,
            rowAxisLabel: $this->axisLabel($viewConfig->rowAxis),
            columnAxisLabel: $viewConfig->columnAxis instanceof AnalysisAxisRef
                ? $this->axisLabel($viewConfig->columnAxis)
                : '',
        );
    }

    private function createMatrixMetricsAsRowsTable(AnalysisViewConfig $viewConfig, AnalysisRunResult $result): ExplorerResultsTableViewModel
    {
        $matrix = AnalysisMatrix::fromRunResult($result);
        $columnLabels = array_values($matrix->columnLabels);
        $rows = [];

        foreach ($matrix->orderedRowKeys as $rowKey) {
            foreach ($result->metricKeys as $metricKey) {
                $seriesValues = [];
                $formattedSeriesValues = [];
                foreach ($matrix->orderedColumnKeys as $colKey) {
                    $label = $matrix->columnLabels[$colKey];
                    $value = $matrix->valueFor($rowKey, $colKey, $metricKey);
                    $seriesValues[$label] = $value;
                    $formattedSeriesValues[$label] = $this->formatMetricValue($metricKey, $value);
                }

                $rows[] = new ExplorerResultsTableRow(
                    bucketLabel: $matrix->rowLabels[$rowKey],
                    formattedMetricValues: [],
                    seriesValues: $seriesValues,
                    formattedSeriesValues: $formattedSeriesValues,
                    metricSubRowLabel: $this->metricLabel($metricKey),
                );
            }
        }

        return new ExplorerResultsTableViewModel(
            primaryDimensionLabel: $this->axisLabel($viewConfig->rowAxis),
            metricColumns: [],
            rows: $rows,
            formattedTotals: $this->formatTotals($result),
            hasSeries: true,
            seriesLabels: $columnLabels,
            tableLayout: TableLayout::MatrixMetricsAsRows,
            rowAxisLabel: $this->axisLabel($viewConfig->rowAxis),
            columnAxisLabel: $viewConfig->columnAxis instanceof AnalysisAxisRef
                ? $this->axisLabel($viewConfig->columnAxis)
                : '',
            hasMetricSubRows: true,
        );
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
     * @return array<string, string>
     */
    private function formatTotals(AnalysisRunResult $result): array
    {
        $formatted = [];
        foreach ($result->metricKeys as $metricKey) {
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
        return $this->translator->trans('stats.analysis_explorer.metric.'.$metricKey->value);
    }
}
