<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\UI\Http\Controller;

use App\Statistics\GenericAnalysis\Application\DTO\EnrichedAnalysisRow;
use App\Statistics\GenericAnalysis\Application\DTO\GenericAnalysisGroupedSeriesCell;
use App\Statistics\GenericAnalysis\Application\DTO\GenericAnalysisGroupedTableRow;
use App\Statistics\GenericAnalysis\Application\DTO\GenericAnalysisTableFooterSeriesCell;
use App\Statistics\GenericAnalysis\Application\DTO\GenericAnalysisTableFooterTotals;
use App\Statistics\GenericAnalysis\Application\DTO\NormalizedAnalysisResult;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisTableLayout;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisTableRowLimiter;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDimensionType;
use App\Statistics\GenericAnalysis\Domain\Enum\GenericAnalysisTableRowLimit;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\UI\Http\Navigation\GenericAnalysisQueryKeys;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use Symfony\Component\HttpFoundation\Request;

final readonly class GenericAnalysisTableViewModelFactory
{
    private const int GROUPED_COLUMN_MIN_WIDTH_PX = 112;
    private const int GROUPED_TABLE_BASE_WIDTH_PX = 200;

    public function __construct(
        private StatisticsNavigationUrlBuilder $navigationUrlBuilder,
        private DimensionRegistry $dimensionRegistry,
        private GenericAnalysisTableRowLimiter $tableRowLimiter,
    ) {
    }

    public function create(
        Request $request,
        string $presetKey,
        NormalizedAnalysisResult $result,
        string $primaryDimensionKey,
    ): GenericAnalysisTableViewModel {
        $supportsGrouped = null !== $result->seriesDimensionLabel;
        $requestedLayout = GenericAnalysisTableLayout::fromRequestValue(
            $request->query->getString(GenericAnalysisQueryKeys::LAYOUT),
        );
        $layout = $supportsGrouped && GenericAnalysisTableLayout::Grouped === $requestedLayout
            ? GenericAnalysisTableLayout::Grouped
            : GenericAnalysisTableLayout::Stacked;

        $primary = $this->dimensionRegistry->get($primaryDimensionKey);
        $primaryIsTemporal = AnalysisDimensionType::Temporal === $primary->type;
        $distinctBucketCount = $this->countDistinctPrimaryBuckets($result->rows);
        $rowLimit = GenericAnalysisTableRowLimit::resolve($request, $distinctBucketCount, $primaryIsTemporal);

        [$stackedRows] = $this->tableRowLimiter->limit(
            $result->rows,
            $rowLimit->cap(),
            $result->grandTotal,
            $result->metricKeys,
        );

        $seriesColumns = $this->extractSeriesColumns($stackedRows);
        $showPercentOfBucket = \in_array('percent_of_bucket', $result->metricKeys, true);
        $showPercentOfTotal = \in_array('percent_of_total', $result->metricKeys, true);
        $groupedRows = $this->buildGroupedRows(
            $stackedRows,
            $seriesColumns,
            $showPercentOfBucket,
            $showPercentOfTotal,
        );

        $seriesColumnCount = \count($seriesColumns);
        $footerTotals = $this->buildFooterTotals(
            $stackedRows,
            $groupedRows,
            $seriesColumns,
            $result->grandTotal,
            $showPercentOfTotal,
        );

        return new GenericAnalysisTableViewModel(
            layout: $layout,
            supportsGroupedLayout: $supportsGrouped,
            stackedLayoutUrl: $this->layoutUrl($request, $presetKey, GenericAnalysisTableLayout::Stacked),
            groupedLayoutUrl: $this->layoutUrl($request, $presetKey, GenericAnalysisTableLayout::Grouped),
            primaryDimensionLabel: $result->primaryDimensionLabel,
            seriesDimensionLabel: $result->seriesDimensionLabel,
            grandTotal: $result->grandTotal,
            stackedRows: $stackedRows,
            seriesColumns: $seriesColumns,
            groupedRows: $groupedRows,
            groupedTableMinWidthPx: self::GROUPED_TABLE_BASE_WIDTH_PX
                + ($seriesColumnCount * self::GROUPED_COLUMN_MIN_WIDTH_PX),
            footerTotals: $footerTotals,
            metricColumns: $result->metricColumns,
            selectedMetricKeys: $result->metricKeys,
            showPercentOfBucket: $showPercentOfBucket,
            showPercentOfTotal: $showPercentOfTotal,
        );
    }

    /**
     * @param list<EnrichedAnalysisRow> $rows
     */
    private function countDistinctPrimaryBuckets(array $rows): int
    {
        $seen = [];
        foreach ($rows as $row) {
            $seen[$row->bucketKey] = true;
        }

        return \count($seen);
    }

    /**
     * @param list<EnrichedAnalysisRow>               $stackedRows
     * @param list<GenericAnalysisGroupedTableRow>    $groupedRows
     * @param list<array{key: string, label: string}> $seriesColumns
     */
    private function buildFooterTotals(
        array $stackedRows,
        array $groupedRows,
        array $seriesColumns,
        int $grandTotal,
        bool $showPercentOfTotal,
    ): GenericAnalysisTableFooterTotals {
        $totalValue = 0;
        foreach ($stackedRows as $row) {
            $totalValue += $row->countValue();
        }

        $seriesCells = [];
        foreach ($seriesColumns as $seriesColumn) {
            $seriesSum = 0;
            foreach ($groupedRows as $groupedRow) {
                $cell = $groupedRow->cellsBySeriesKey[$seriesColumn['key']] ?? null;
                if (null !== $cell) {
                    $seriesSum += $cell->value;
                }
            }
            $seriesCells[$seriesColumn['key']] = new GenericAnalysisTableFooterSeriesCell(
                value: $seriesSum,
                percentOfGrandTotal: $showPercentOfTotal
                    ? $this->percentOfGrandTotal($seriesSum, $grandTotal)
                    : 0.0,
            );
        }

        return new GenericAnalysisTableFooterTotals(
            totalValue: $totalValue,
            percentOfGrandTotal: $showPercentOfTotal
                ? $this->percentOfGrandTotal($totalValue, $grandTotal)
                : 0.0,
            seriesCellsByKey: $seriesCells,
        );
    }

    private function percentOfGrandTotal(int $value, int $grandTotal): float
    {
        if ($grandTotal <= 0) {
            return 0.0;
        }

        return ((float) $value / (float) $grandTotal) * 100.0;
    }

    private function layoutUrl(
        Request $request,
        string $presetKey,
        GenericAnalysisTableLayout $layout,
    ): string {
        return $this->navigationUrlBuilder->build(
            $request,
            'app_stats_generic_analysis',
            [
                'presetKey' => $presetKey,
                GenericAnalysisQueryKeys::LAYOUT => $layout->value,
            ],
        );
    }

    /**
     * @param list<EnrichedAnalysisRow> $rows
     *
     * @return list<array{key: string, label: string}>
     */
    private function extractSeriesColumns(array $rows): array
    {
        $columns = [];
        $seen = [];
        foreach ($rows as $row) {
            if (null === $row->seriesKey || null === $row->seriesLabel) {
                continue;
            }
            if (isset($seen[$row->seriesKey])) {
                continue;
            }
            $seen[$row->seriesKey] = true;
            $columns[] = [
                'key' => $row->seriesKey,
                'label' => $row->seriesLabel,
            ];
        }

        return $columns;
    }

    /**
     * @param list<EnrichedAnalysisRow>               $rows
     * @param list<array{key: string, label: string}> $seriesColumns
     *
     * @return list<GenericAnalysisGroupedTableRow>
     */
    private function buildGroupedRows(
        array $rows,
        array $seriesColumns,
        bool $showPercentOfBucket,
        bool $showPercentOfTotal,
    ): array {
        if ([] === $seriesColumns) {
            return [];
        }

        /** @var array<string, array{bucketLabel: string, cells: array<string, GenericAnalysisGroupedSeriesCell|null>}> $byBucket */
        $byBucket = [];
        $seriesKeys = array_map(static fn (array $col): string => $col['key'], $seriesColumns);

        foreach ($rows as $row) {
            $bucketKey = $row->bucketKey;
            if (!isset($byBucket[$bucketKey])) {
                $cells = [];
                foreach ($seriesKeys as $seriesKey) {
                    $cells[$seriesKey] = null;
                }
                $byBucket[$bucketKey] = [
                    'bucketLabel' => $row->bucketLabel,
                    'cells' => $cells,
                ];
            }

            if (null !== $row->seriesKey) {
                $byBucket[$bucketKey]['cells'][$row->seriesKey] = new GenericAnalysisGroupedSeriesCell(
                    value: $row->countValue(),
                    percentOfTotal: $showPercentOfTotal
                        ? (float) ($row->metrics['percent_of_total'] ?? 0.0)
                        : 0.0,
                    percentOfBucket: $showPercentOfBucket
                        ? (float) ($row->metrics['percent_of_bucket'] ?? 0.0)
                        : 0.0,
                );
            }
        }

        $grouped = [];
        foreach ($byBucket as $bucketKey => $bucket) {
            $bucketTotal = 0;
            foreach ($bucket['cells'] as $cell) {
                if (null !== $cell) {
                    $bucketTotal += $cell->value;
                }
            }

            $grouped[] = new GenericAnalysisGroupedTableRow(
                bucketKey: $this->stringifyArrayKey($bucketKey),
                bucketLabel: $bucket['bucketLabel'],
                cellsBySeriesKey: $this->normalizeCellsBySeriesKey($bucket['cells']),
                bucketTotal: $bucketTotal,
            );
        }

        return $grouped;
    }

    /**
     * @param array<int|string, GenericAnalysisGroupedSeriesCell|null> $cells
     *
     * @return array<string, GenericAnalysisGroupedSeriesCell|null>
     */
    private function normalizeCellsBySeriesKey(array $cells): array
    {
        $normalized = [];
        foreach ($cells as $seriesKey => $cell) {
            $normalized[(string) $seriesKey] = $cell;
        }

        return $normalized;
    }

    private function stringifyArrayKey(int|string $key): string
    {
        return (string) $key;
    }
}
