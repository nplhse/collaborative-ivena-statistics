<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application\DTO;

use App\Statistics\AnalysisExplorer\Domain\Enum\TableLayout;

final readonly class ExplorerResultsTableViewModel
{
    /**
     * @param list<ExplorerResultsTableMetricColumn> $metricColumns
     * @param list<ExplorerResultsTableRow>          $rows
     * @param array<string, string>                  $formattedTotals
     * @param list<string>                           $seriesLabels
     * @param array<string, string>                  $formattedSeriesTotals
     * @param array<string, string>                  $formattedSeriesFooterPercentValues
     * @param array<string, string>                  $formattedTotalsPercentValues
     */
    public function __construct(
        public string $primaryDimensionLabel,
        public array $metricColumns,
        public array $rows,
        public array $formattedTotals,
        public bool $hasSeries = false,
        public array $seriesLabels = [],
        public array $formattedSeriesTotals = [],
        public string $formattedGrandTotal = '0',
        public TableLayout $tableLayout = TableLayout::Flat,
        public string $rowAxisLabel = '',
        public string $columnAxisLabel = '',
        public bool $hasMetricSubRows = false,
        public bool $showPercentOfTotal = false,
        public array $formattedSeriesFooterPercentValues = [],
        public string $formattedGrandTotalPercent = '',
        public array $formattedTotalsPercentValues = [],
        public string $footerRowLabel = '',
    ) {
    }

    public function hasData(): bool
    {
        return [] !== $this->rows;
    }

    public function columnCount(): int
    {
        if ($this->hasMetricSubRows) {
            return 2 + \count($this->seriesLabels);
        }

        if ($this->hasSeries) {
            return \count($this->seriesLabels) + 2;
        }

        return 1 + \count($this->metricColumns);
    }

    public function isClientSortable(): bool
    {
        return $this->hasData() && !$this->hasMetricSubRows;
    }
}
