<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Application\DTO\AnalysisMatrix;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\BoxPlotTableColumn;
use App\Statistics\AnalysisExplorer\Domain\Enum\TableLayout;
use App\Statistics\GenericAnalysis\Application\Export\TabularExportColumn;
use App\Statistics\GenericAnalysis\Application\Export\TabularExportDocument;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ExplorerResultsTableExportBuilder
{
    public function __construct(
        private TranslatorInterface $translator,
        private ExplorerTablePercentHelper $percentHelper,
        private ExplorerMetricSummabilityPolicy $summabilityPolicy,
        private ExplorerMetricProfileRegistry $profileRegistry,
    ) {
    }

    public function build(AnalysisViewConfig $viewConfig, AnalysisRunResult $result): TabularExportDocument
    {
        if (!$result->hasColumnAxis()) {
            return $this->buildFlatDocument($viewConfig, $result);
        }

        return match ($viewConfig->presentation->tableLayout) {
            TableLayout::MatrixMetricsAsRows => $this->buildMatrixMetricsAsRowsDocument($viewConfig, $result),
            default => $this->buildMatrixDocument($viewConfig, $result),
        };
    }

    private function buildFlatDocument(AnalysisViewConfig $viewConfig, AnalysisRunResult $result): TabularExportDocument
    {
        if ($viewConfig->visualMetricKey->isDistributionProfile()) {
            return $this->buildDistributionFlatDocument($viewConfig, $result);
        }

        $showPercent = $viewConfig->showsPercentOfTotal();
        $headers = [
            new TabularExportColumn('row', $this->axisLabel($viewConfig->rowAxis)),
        ];
        foreach ($result->metricKeys as $metricKey) {
            if ($showPercent && AnalysisMetricKey::PercentOfTotal === $metricKey) {
                continue;
            }

            $headers[] = new TabularExportColumn($metricKey->value, $this->metricLabel($metricKey));
            if ($showPercent && AnalysisMetricKey::AllocationCount === $metricKey) {
                $headers[] = new TabularExportColumn(
                    'percent_of_total',
                    $this->translator->trans('stats.analysis_explorer.export.percent_of_total'),
                );
            }
        }

        $grandTotal = $result->totalFor(AnalysisMetricKey::AllocationCount);
        $rows = [];
        foreach ($result->rows as $row) {
            $cells = [$row->bucketLabel];
            foreach ($result->metricKeys as $metricKey) {
                if ($showPercent && AnalysisMetricKey::PercentOfTotal === $metricKey) {
                    continue;
                }

                $cells[] = $row->valueFor($metricKey);
                if ($showPercent && AnalysisMetricKey::AllocationCount === $metricKey) {
                    $percent = $row->valueFor(AnalysisMetricKey::PercentOfTotal);
                    if (null === $percent) {
                        $percent = $this->percentHelper->percentOfTotal(
                            $row->valueFor(AnalysisMetricKey::AllocationCount),
                            $grandTotal,
                        );
                    }
                    $cells[] = $percent;
                }
            }
            $rows[] = $cells;
        }

        $footerRows = [];
        if ([] !== $result->rows) {
            $footer = [$this->totalLabel()];
            foreach ($result->metricKeys as $metricKey) {
                if ($showPercent && AnalysisMetricKey::PercentOfTotal === $metricKey) {
                    continue;
                }

                $footer[] = $result->totalFor($metricKey);
                if ($showPercent && AnalysisMetricKey::AllocationCount === $metricKey) {
                    $footer[] = 100.0;
                }
            }
            $footerRows[] = $footer;
        }

        return new TabularExportDocument($headers, $rows, $footerRows);
    }

    private function buildDistributionFlatDocument(AnalysisViewConfig $viewConfig, AnalysisRunResult $result): TabularExportDocument
    {
        $tableColumns = $this->profileRegistry->tableColumnsFor($viewConfig->visualMetricKey);
        $headers = [
            new TabularExportColumn('row', $this->axisLabel($viewConfig->rowAxis)),
        ];
        foreach ($tableColumns as $column) {
            $headers[] = new TabularExportColumn(
                $column->value,
                $this->translator->trans($column->labelTranslationKey()),
            );
        }

        $rows = [];
        foreach ($result->rows as $row) {
            $cells = [$row->bucketLabel];
            foreach ($tableColumns as $column) {
                $cells[] = $this->rawBoxPlotColumnValue($column, $row->boxPlot);
            }
            $rows[] = $cells;
        }

        return new TabularExportDocument($headers, $rows);
    }

    private function rawBoxPlotColumnValue(BoxPlotTableColumn $column, ?\App\Statistics\AnalysisExplorer\Domain\DTO\BoxPlotStats $boxPlot): int|float|null
    {
        if (!$boxPlot instanceof \App\Statistics\AnalysisExplorer\Domain\DTO\BoxPlotStats) {
            return null;
        }

        return match ($column) {
            BoxPlotTableColumn::Count => $boxPlot->count,
            BoxPlotTableColumn::Min => $boxPlot->minimum,
            BoxPlotTableColumn::P25 => $boxPlot->p25,
            BoxPlotTableColumn::Median => $boxPlot->median,
            BoxPlotTableColumn::P75 => $boxPlot->p75,
            BoxPlotTableColumn::Max => $boxPlot->maximum,
        };
    }

    private function buildMatrixDocument(AnalysisViewConfig $viewConfig, AnalysisRunResult $result): TabularExportDocument
    {
        $visualMetricKey = $result->visualMetricKey;
        $summable = $this->summabilityPolicy->isSummable($visualMetricKey);
        $showPercent = $viewConfig->showsPercentOfTotal() && $summable;
        $matrix = AnalysisMatrix::fromRunResult($result);
        $headers = [
            new TabularExportColumn('row', $this->axisLabel($viewConfig->rowAxis)),
        ];
        foreach ($matrix->orderedColumnKeys as $colKey) {
            $headers[] = new TabularExportColumn($colKey, $matrix->columnLabels[$colKey]);
            if ($showPercent) {
                $headers[] = new TabularExportColumn(
                    $colKey.'_percent',
                    $matrix->columnLabels[$colKey].' '.$this->translator->trans('stats.analysis_explorer.export.percent_of_row'),
                );
            }
        }
        $headers[] = new TabularExportColumn('total', $this->totalLabel());
        if ($showPercent) {
            $headers[] = new TabularExportColumn(
                'total_percent',
                $this->totalLabel().' '.$this->translator->trans('stats.analysis_explorer.export.percent_of_total'),
            );
        }

        $grandTotal = $summable ? $result->totalFor($visualMetricKey) : null;
        $rows = [];
        foreach ($matrix->orderedRowKeys as $rowKey) {
            $cells = [$matrix->rowLabels[$rowKey]];
            $rowTotal = 0.0;
            $columnValues = [];
            foreach ($matrix->orderedColumnKeys as $colKey) {
                $value = $this->rawMatrixValue($matrix, $rowKey, $colKey, $visualMetricKey);
                $columnValues[] = $value;
                if ($summable) {
                    $rowTotal += null === $value ? 0.0 : (float) $value;
                }
            }

            foreach ($columnValues as $value) {
                $cells[] = $value;
                if ($showPercent) {
                    $cells[] = $this->percentHelper->percentOfRow($value, $rowTotal);
                }
            }

            $cells[] = $summable ? $rowTotal : null;
            if ($showPercent) {
                $cells[] = $this->percentHelper->percentOfTotal($rowTotal, $grandTotal);
            }
            $rows[] = $cells;
        }

        $footerRows = [];
        if ([] !== $matrix->orderedRowKeys) {
            $footer = [$this->totalLabel()];
            $columnGrandTotal = 0.0;
            foreach ($matrix->orderedColumnKeys as $colKey) {
                $columnTotal = $result->totals->byColumn[$colKey][$visualMetricKey->value] ?? null;
                $footer[] = $columnTotal;
                if ($summable) {
                    $columnGrandTotal += null === $columnTotal ? 0.0 : (float) $columnTotal;
                }
                if ($showPercent) {
                    $footer[] = $this->percentHelper->percentOfTotal($columnTotal, $grandTotal);
                }
            }
            $footer[] = $summable ? $columnGrandTotal : null;
            if ($showPercent) {
                $footer[] = 100.0;
            }
            $footerRows[] = $footer;
        }

        return new TabularExportDocument($headers, $rows, $footerRows);
    }

    private function buildMatrixMetricsAsRowsDocument(AnalysisViewConfig $viewConfig, AnalysisRunResult $result): TabularExportDocument
    {
        $showPercent = $viewConfig->showsPercentOfTotal();
        $matrix = AnalysisMatrix::fromRunResult($result);
        $metricKeys = array_values(array_filter(
            $result->metricKeys,
            static fn (AnalysisMetricKey $metricKey): bool => !$showPercent || AnalysisMetricKey::PercentOfTotal !== $metricKey,
        ));
        $headers = [
            new TabularExportColumn('row', $this->axisLabel($viewConfig->rowAxis)),
            new TabularExportColumn('metric', $this->translator->trans('stats.analysis_explorer.table.metric')),
        ];
        foreach ($matrix->orderedColumnKeys as $colKey) {
            $headers[] = new TabularExportColumn($colKey, $matrix->columnLabels[$colKey]);
            if ($showPercent) {
                $headers[] = new TabularExportColumn(
                    $colKey.'_percent',
                    $matrix->columnLabels[$colKey].' '.$this->translator->trans('stats.analysis_explorer.export.percent_of_row'),
                );
            }
        }

        $rows = [];
        foreach ($matrix->orderedRowKeys as $rowKey) {
            foreach ($metricKeys as $metricKey) {
                $cells = [
                    $matrix->rowLabels[$rowKey],
                    $this->metricLabel($metricKey),
                ];
                $rowTotal = 0.0;
                $columnValues = [];
                foreach ($matrix->orderedColumnKeys as $colKey) {
                    $value = $this->rawMatrixValue($matrix, $rowKey, $colKey, $metricKey);
                    $columnValues[] = $value;
                    $rowTotal += null === $value ? 0.0 : (float) $value;
                }

                foreach ($columnValues as $value) {
                    $cells[] = $value;
                    if ($showPercent && AnalysisMetricKey::AllocationCount === $metricKey) {
                        $cells[] = $this->percentHelper->percentOfRow($value, $rowTotal);
                    } elseif ($showPercent) {
                        $cells[] = null;
                    }
                }
                $rows[] = $cells;
            }
        }

        return new TabularExportDocument($headers, $rows);
    }

    private function rawMatrixValue(
        AnalysisMatrix $matrix,
        string $rowKey,
        string $colKey,
        AnalysisMetricKey $metricKey,
    ): int|float|null {
        return $matrix->cells[$rowKey][$colKey][$metricKey->value] ?? null;
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

    private function totalLabel(): string
    {
        return $this->translator->trans('stats.analysis_explorer.table.footer_total');
    }
}
