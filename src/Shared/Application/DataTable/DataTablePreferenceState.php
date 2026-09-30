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
     * Visible columns in the configured left-to-right order.
     *
     * @return list<string>
     */
    public function visibleOrderedKeys(): array
    {
        $visible = array_fill_keys($this->visibleColumns, true);
        $keys = [];
        foreach ($this->columnOrder as $key) {
            if (isset($visible[$key])) {
                $keys[] = $key;
            }
        }

        return $keys;
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
