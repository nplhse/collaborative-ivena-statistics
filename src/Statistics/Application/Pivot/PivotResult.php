<?php

declare(strict_types=1);

namespace App\Statistics\Application\Pivot;

final readonly class PivotResult
{
    /**
     * @param list<string>      $rowLabels
     * @param list<string>      $columnLabels
     * @param list<list<float>> $matrix
     * @param list<float>       $rowTotals
     * @param list<float>       $columnTotals
     */
    public function __construct(
        public array $rowLabels,
        public array $columnLabels,
        public array $matrix,
        public array $rowTotals,
        public array $columnTotals,
        public float $grandTotal,
    ) {
    }
}
