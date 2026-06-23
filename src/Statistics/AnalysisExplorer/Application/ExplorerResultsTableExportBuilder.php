<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Application\DTO\AnalysisMatrix;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\TableLayout;
use App\Statistics\GenericAnalysis\Application\Export\TabularExportColumn;
use App\Statistics\GenericAnalysis\Application\Export\TabularExportDocument;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ExplorerResultsTableExportBuilder
{
    public function __construct(
        private TranslatorInterface $translator,
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
        $headers = [
            new TabularExportColumn('row', $this->axisLabel($viewConfig->rowAxis)),
        ];
        foreach ($result->metricKeys as $metricKey) {
            $headers[] = new TabularExportColumn($metricKey->value, $this->metricLabel($metricKey));
        }

        $rows = [];
        foreach ($result->rows as $row) {
            $cells = [$row->bucketLabel];
            foreach ($result->metricKeys as $metricKey) {
                $cells[] = $row->valueFor($metricKey);
            }
            $rows[] = $cells;
        }

        $footerRows = [];
        if ([] !== $result->rows) {
            $footer = [$this->totalLabel()];
            foreach ($result->metricKeys as $metricKey) {
                $footer[] = $result->totalFor($metricKey);
            }
            $footerRows[] = $footer;
        }

        return new TabularExportDocument($headers, $rows, $footerRows);
    }

    private function buildMatrixDocument(AnalysisViewConfig $viewConfig, AnalysisRunResult $result): TabularExportDocument
    {
        $matrix = AnalysisMatrix::fromRunResult($result);
        $headers = [
            new TabularExportColumn('row', $this->axisLabel($viewConfig->rowAxis)),
        ];
        foreach ($matrix->orderedColumnKeys as $colKey) {
            $headers[] = new TabularExportColumn($colKey, $matrix->columnLabels[$colKey]);
        }
        $headers[] = new TabularExportColumn('total', $this->totalLabel());

        $rows = [];
        foreach ($matrix->orderedRowKeys as $rowKey) {
            $cells = [$matrix->rowLabels[$rowKey]];
            $rowTotal = 0.0;
            foreach ($matrix->orderedColumnKeys as $colKey) {
                $value = $this->rawMatrixValue($matrix, $rowKey, $colKey, $result->visualMetricKey);
                $cells[] = $value;
                $rowTotal += null === $value ? 0.0 : (float) $value;
            }
            $cells[] = $rowTotal;
            $rows[] = $cells;
        }

        $footerRows = [];
        if ([] !== $matrix->orderedRowKeys) {
            $footer = [$this->totalLabel()];
            $grandTotal = 0.0;
            foreach ($matrix->orderedColumnKeys as $colKey) {
                $columnTotal = $result->totals->byColumn[$colKey][$result->visualMetricKey->value] ?? null;
                $footer[] = $columnTotal;
                $grandTotal += null === $columnTotal ? 0.0 : (float) $columnTotal;
            }
            $footer[] = $grandTotal;
            $footerRows[] = $footer;
        }

        return new TabularExportDocument($headers, $rows, $footerRows);
    }

    private function buildMatrixMetricsAsRowsDocument(AnalysisViewConfig $viewConfig, AnalysisRunResult $result): TabularExportDocument
    {
        $matrix = AnalysisMatrix::fromRunResult($result);
        $headers = [
            new TabularExportColumn('row', $this->axisLabel($viewConfig->rowAxis)),
            new TabularExportColumn('metric', $this->translator->trans('stats.analysis_explorer.table.metric')),
        ];
        foreach ($matrix->orderedColumnKeys as $colKey) {
            $headers[] = new TabularExportColumn($colKey, $matrix->columnLabels[$colKey]);
        }

        $rows = [];
        foreach ($matrix->orderedRowKeys as $rowKey) {
            foreach ($result->metricKeys as $metricKey) {
                $cells = [
                    $matrix->rowLabels[$rowKey],
                    $this->metricLabel($metricKey),
                ];
                foreach ($matrix->orderedColumnKeys as $colKey) {
                    $cells[] = $this->rawMatrixValue($matrix, $rowKey, $colKey, $metricKey);
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
        return $this->translator->trans('stats.analysis_explorer.metric.'.$metricKey->value);
    }

    private function totalLabel(): string
    {
        return $this->translator->trans('stats.analysis_explorer.table.footer_total');
    }
}
