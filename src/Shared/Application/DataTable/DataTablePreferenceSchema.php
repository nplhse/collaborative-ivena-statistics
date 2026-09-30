<?php

declare(strict_types=1);

namespace App\Shared\Application\DataTable;

final readonly class DataTablePreferenceSchema
{
    /**
     * @param list<string> $columnOrder
     * @param list<string> $defaultVisibleColumns
     * @param list<string> $requiredColumns
     * @param list<int>    $pageSizes
     */
    public function __construct(
        public string $key,
        public array $columnOrder,
        public array $defaultVisibleColumns,
        public array $requiredColumns,
        public array $pageSizes = [25, 50, 100],
        public int $defaultPageSize = 25,
    ) {
        if ('' === $key || [] === $columnOrder) {
            throw new \InvalidArgumentException('DataTable preference schema requires a key and columns.');
        }
    }

    /** @psalm-suppress PossiblyUnusedMethod Used by tests and future consumers. */
    public function defaults(): DataTablePreferenceState
    {
        return new DataTablePreferenceState(
            $this->defaultVisibleColumns,
            $this->columnOrder,
            $this->defaultPageSize,
        );
    }
}
