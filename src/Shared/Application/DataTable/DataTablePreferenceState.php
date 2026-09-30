<?php

declare(strict_types=1);

namespace App\Shared\Application\DataTable;

final readonly class DataTablePreferenceState
{
    /**
     * @param list<string> $visibleColumns
     * @param list<string> $columnOrder
     */
    public function __construct(
        public array $visibleColumns,
        public array $columnOrder,
        public int $pageSize,
    ) {
    }

    /**
     * @return array{visibleColumns: list<string>, columnOrder: list<string>, pageSize: int}
     */
    public function toArray(): array
    {
        return [
            'visibleColumns' => $this->visibleColumns,
            'columnOrder' => $this->columnOrder,
            'pageSize' => $this->pageSize,
        ];
    }
}
