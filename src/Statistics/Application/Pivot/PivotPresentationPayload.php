<?php

declare(strict_types=1);

namespace App\Statistics\Application\Pivot;

final readonly class PivotPresentationPayload
{
    /**
     * @param list<list<string>> $matrix
     * @param list<string>       $rowTotals
     * @param list<string>       $columnTotals
     */
    public function __construct(
        public array $matrix,
        public array $rowTotals,
        public array $columnTotals,
        public string $grandTotal,
    ) {
    }
}
