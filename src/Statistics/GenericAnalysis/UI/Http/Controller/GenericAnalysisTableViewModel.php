<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\UI\Http\Controller;

use App\Statistics\GenericAnalysis\Application\DTO\EnrichedAnalysisRow;
use App\Statistics\GenericAnalysis\Application\DTO\GenericAnalysisGroupedTableRow;
use App\Statistics\GenericAnalysis\Application\DTO\GenericAnalysisTableFooterTotals;
use App\Statistics\GenericAnalysis\Application\DTO\GenericAnalysisTableMetricColumn;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisTableLayout;

final readonly class GenericAnalysisTableViewModel
{
    /**
     * @param list<EnrichedAnalysisRow>               $stackedRows
     * @param list<array{key: string, label: string}> $seriesColumns
     * @param list<GenericAnalysisGroupedTableRow>    $groupedRows
     * @param list<GenericAnalysisTableMetricColumn>  $metricColumns
     * @param list<string>                            $selectedMetricKeys
     */
    public function __construct(
        public GenericAnalysisTableLayout $layout,
        public bool $supportsGroupedLayout,
        public string $stackedLayoutUrl,
        public string $groupedLayoutUrl,
        public string $primaryDimensionLabel,
        public ?string $seriesDimensionLabel,
        public int $grandTotal,
        public array $stackedRows,
        public array $seriesColumns,
        public array $groupedRows,
        public int $groupedTableMinWidthPx,
        public GenericAnalysisTableFooterTotals $footerTotals,
        public array $metricColumns,
        public array $selectedMetricKeys,
        public bool $showPercentOfBucket,
        public bool $showPercentOfTotal,
    ) {
    }

    public function isGrouped(): bool
    {
        return GenericAnalysisTableLayout::Grouped === $this->layout && $this->supportsGroupedLayout;
    }
}
