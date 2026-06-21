<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application\DTO;

final readonly class ExplorerResultsTableViewModel
{
    /**
     * @param list<ExplorerResultsTableRow> $rows
     */
    public function __construct(
        public string $primaryDimensionLabel,
        public string $metricLabel,
        public array $rows,
        public int $total,
    ) {
    }

    public function hasData(): bool
    {
        return [] !== $this->rows;
    }
}
