<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application\DTO;

final readonly class ExplorerResultsTableViewModel
{
    /**
     * @param list<ExplorerResultsTableMetricColumn> $metricColumns
     * @param list<ExplorerResultsTableRow>          $rows
     * @param array<string, string>                  $formattedTotals
     * @param list<string>                           $seriesLabels
     * @param array<string, string>                  $formattedSeriesTotals
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
    ) {
    }

    public function hasData(): bool
    {
        return [] !== $this->rows;
    }

    public function columnCount(): int
    {
        if ($this->hasSeries) {
            return \count($this->seriesLabels) + 2;
        }

        return 1 + \count($this->metricColumns);
    }
}
