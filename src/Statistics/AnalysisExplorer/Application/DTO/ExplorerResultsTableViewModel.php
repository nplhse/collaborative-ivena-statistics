<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application\DTO;

final readonly class ExplorerResultsTableViewModel
{
    /**
     * @param list<ExplorerResultsTableRow> $rows
     * @param list<string>                  $seriesLabels
     * @param array<string, int>            $seriesTotals
     */
    public function __construct(
        public string $primaryDimensionLabel,
        public string $metricLabel,
        public array $rows,
        public int $total,
        public bool $hasSeries = false,
        public array $seriesLabels = [],
        public array $seriesTotals = [],
    ) {
    }

    public function hasData(): bool
    {
        return [] !== $this->rows;
    }
}
