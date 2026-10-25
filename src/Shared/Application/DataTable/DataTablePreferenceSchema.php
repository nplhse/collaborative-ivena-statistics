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
     * @param list<string> $sortKeys
     */
    public function __construct(
        public string $key,
        public array $columnOrder,
        public array $defaultVisibleColumns,
        public array $requiredColumns,
        public array $pageSizes = [25, 50, 100],
        public int $defaultPageSize = 25,
        public array $sortKeys = [],
        public ?string $defaultSortBy = null,
        public string $defaultOrderBy = 'desc',
    ) {
        if ('' === $key || [] === $columnOrder) {
            throw new \InvalidArgumentException('DataTable preference schema requires a key and columns.');
        }
    }

    /**
     * Builds the schema from declarative DataTable column configs: every keyed column is
     * part of the order, `visible` (default true) or `required` columns are visible by default.
     *
     * @param list<array<string, mixed>> $columns
     * @param list<string>               $sortKeys
     */
    public static function fromColumns(
        string $key,
        array $columns,
        array $sortKeys = [],
        ?string $defaultSortBy = null,
        string $defaultOrderBy = 'desc',
        int $defaultPageSize = 25,
    ): self {
        $order = [];
        $visible = [];
        $required = [];
        foreach ($columns as $column) {
            $columnKey = $column['key'] ?? null;
            if (!\is_string($columnKey) || '' === $columnKey) {
                continue;
            }
            $order[] = $columnKey;
            $isRequired = true === ($column['required'] ?? false);
            if ($isRequired || false !== ($column['visible'] ?? true)) {
                $visible[] = $columnKey;
            }
            if ($isRequired) {
                $required[] = $columnKey;
            }
        }

        return new self(
            $key,
            $order,
            $visible,
            $required,
            defaultPageSize: $defaultPageSize,
            sortKeys: $sortKeys,
            defaultSortBy: $defaultSortBy,
            defaultOrderBy: $defaultOrderBy,
        );
    }

    /** @psalm-suppress PossiblyUnusedMethod Used by tests and future consumers. */
    public function defaults(): DataTablePreferenceState
    {
        return new DataTablePreferenceState(
            $this->defaultVisibleColumns,
            $this->columnOrder,
            $this->defaultPageSize,
            [] === $this->sortKeys ? null : $this->defaultSortBy,
            [] === $this->sortKeys ? null : $this->defaultOrderBy,
        );
    }
}
