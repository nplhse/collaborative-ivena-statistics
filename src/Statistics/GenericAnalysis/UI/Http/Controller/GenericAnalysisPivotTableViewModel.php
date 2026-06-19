<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\UI\Http\Controller;

final readonly class GenericAnalysisPivotTableViewModel
{
    /**
     * @param list<string>       $rowLabels
     * @param list<string>       $columnLabels
     * @param list<list<string>> $matrix
     * @param list<string>       $rowTotals
     * @param list<string>       $columnTotals
     */
    public function __construct(
        public string $rowDimensionLabel,
        public string $columnDimensionLabel,
        public array $rowLabels,
        public array $columnLabels,
        public array $matrix,
        public array $rowTotals,
        public array $columnTotals,
        public string $grandTotal,
        public string $rowTotalHeaderLabel,
        public string $columnTotalFooterLabel,
        public bool $showTotals = true,
    ) {
    }
}
