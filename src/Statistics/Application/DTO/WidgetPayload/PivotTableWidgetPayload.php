<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO\WidgetPayload;

final readonly class PivotTableWidgetPayload implements WidgetPayloadInterface
{
    /**
     * @param list<string>               $rowLabels
     * @param list<string>               $columnLabels
     * @param list<list<string>>         $matrix
     * @param list<string>               $rowTotals
     * @param list<string>               $columnTotals
     * @param array<string, mixed>       $extra
     */
    public function __construct(
        private string $rowDimensionLabel,
        private string $columnDimensionLabel,
        private array $rowLabels,
        private array $columnLabels,
        private array $matrix,
        private array $rowTotals,
        private array $columnTotals,
        private string $grandTotal,
        private array $extra = [],
    ) {
    }

    public function toArray(): array
    {
        return array_merge([
            'rowDimensionLabel' => $this->rowDimensionLabel,
            'columnDimensionLabel' => $this->columnDimensionLabel,
            'rowLabels' => $this->rowLabels,
            'columnLabels' => $this->columnLabels,
            'matrix' => $this->matrix,
            'row_totals' => $this->rowTotals,
            'column_totals' => $this->columnTotals,
            'grand_total' => $this->grandTotal,
        ], $this->extra);
    }
}
