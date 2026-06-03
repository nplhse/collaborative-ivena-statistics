<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application\DTO;

final readonly class GenericAnalysisTableFooterSeriesCell
{
    public function __construct(
        public int $value,
        public float $percentOfGrandTotal,
    ) {
    }
}
