<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application\DTO;

final readonly class GenericAnalysisTableFooterTotals
{
    /**
     * @param array<string, GenericAnalysisTableFooterSeriesCell> $seriesCellsByKey
     */
    public function __construct(
        public int $totalValue,
        public float $percentOfGrandTotal,
        public array $seriesCellsByKey = [],
    ) {
    }
}
