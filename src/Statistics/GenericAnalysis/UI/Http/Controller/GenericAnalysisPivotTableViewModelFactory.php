<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\UI\Http\Controller;

use App\Statistics\Application\Pivot\PivotTableBuilder;
use App\Statistics\GenericAnalysis\Application\DTO\EnrichedAnalysisRow;
use App\Statistics\GenericAnalysis\Application\DTO\NormalizedAnalysisResult;
use App\Statistics\GenericAnalysis\Application\MetricValueFormatter;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDisplayMode;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class GenericAnalysisPivotTableViewModelFactory
{
    public function __construct(
        private DimensionRegistry $dimensionRegistry,
        private MetricRegistry $metricRegistry,
        private MetricValueFormatter $metricValueFormatter,
        private PivotTableBuilder $pivotTableBuilder,
        private TranslatorInterface $translator,
    ) {
    }

    public function create(
        AnalysisQuery $query,
        NormalizedAnalysisResult $result,
    ): ?GenericAnalysisPivotTableViewModel {
        if (AnalysisDisplayMode::PivotTable !== $query->displayMode) {
            return null;
        }

        if (null === $query->seriesDimensionKey) {
            return null;
        }

        $primary = $this->dimensionRegistry->get($query->primaryDimensionKey);
        $series = $this->dimensionRegistry->get($query->seriesDimensionKey);
        $measureKey = $query->resolvedVisualMetricKey();

        $rowKeys = [];
        $colKeys = [];
        $cells = [];

        foreach ($result->rows as $row) {
            if (null === $row->seriesKey) {
                continue;
            }
            $rowKey = (string) $row->bucketKey;
            $colKey = (string) $row->seriesKey;
            if (!\in_array($rowKey, $rowKeys, true)) {
                $rowKeys[] = $rowKey;
            }
            if (!\in_array($colKey, $colKeys, true)) {
                $colKeys[] = $colKey;
            }
            $value = $row->metrics[$measureKey] ?? 0;
            $cells[] = [
                'row_key' => $rowKey,
                'col_key' => $colKey,
                'value' => (float) $value,
            ];
        }

        if ([] === $rowKeys || [] === $colKeys) {
            return null;
        }

        $rowLabels = $this->labelMap($result->rows, true);
        $colLabels = $this->labelMap($result->rows, false);

        $pivot = $this->pivotTableBuilder->build($rowKeys, $colKeys, $cells, $rowLabels, $colLabels);

        $metric = $this->metricRegistry->get($measureKey);
        $matrix = [];
        foreach ($pivot->matrix as $rowIndex => $row) {
            $formattedRow = [];
            foreach ($row as $value) {
                $formattedRow[] = $this->metricValueFormatter->format($metric, $value);
            }
            $matrix[] = $formattedRow;
        }

        $rowTotals = array_map(
            fn (float $value): string => $this->metricValueFormatter->format($metric, $value),
            $pivot->rowTotals,
        );
        $columnTotals = array_map(
            fn (float $value): string => $this->metricValueFormatter->format($metric, $value),
            $pivot->columnTotals,
        );

        return new GenericAnalysisPivotTableViewModel(
            rowDimensionLabel: $primary->label,
            columnDimensionLabel: $series->label,
            rowLabels: $pivot->rowLabels,
            columnLabels: $pivot->columnLabels,
            matrix: $matrix,
            rowTotals: $rowTotals,
            columnTotals: $columnTotals,
            grandTotal: $this->metricValueFormatter->format($metric, $pivot->grandTotal),
            rowTotalHeaderLabel: $this->translator->trans('stats.analysis.pivot.row_total'),
            columnTotalFooterLabel: $this->translator->trans('stats.analysis.pivot.column_total'),
        );
    }

    /**
     * @param list<EnrichedAnalysisRow> $rows
     *
     * @return array<string, string>
     */
    private function labelMap(array $rows, bool $useBucket): array
    {
        $labels = [];
        foreach ($rows as $row) {
            if ($useBucket) {
                $labels[(string) $row->bucketKey] = $row->bucketLabel;
            } elseif (null !== $row->seriesKey) {
                $labels[(string) $row->seriesKey] = $row->seriesLabel ?? (string) $row->seriesKey;
            }
        }

        return $labels;
    }
}
