<?php

declare(strict_types=1);

namespace App\Shared\UI\Http;

use Symfony\Component\HttpFoundation\Request;

final readonly class DataTablePreferenceQueryState
{
    /**
     * @param list<string>|null $visibleColumns
     * @param list<string>|null $columnOrder
     */
    public function __construct(
        public ?array $visibleColumns,
        public ?array $columnOrder,
        public ?int $pageSize,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $query = $request->query->all();

        return new self(
            self::stringList($query, 'columns'),
            self::stringList($query, 'columnOrder'),
            self::integer($query, 'limit'),
        );
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return list<string>|null
     */
    private static function stringList(array $query, string $key): ?array
    {
        if (!\array_key_exists($key, $query)) {
            return null;
        }
        if (!\is_string($query[$key])) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(trim(...), explode(',', $query[$key])),
            static fn (string $value): bool => '' !== $value,
        )));
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function integer(array $query, string $key): ?int
    {
        if (!\array_key_exists($key, $query) || (!\is_int($query[$key]) && !\is_string($query[$key]))) {
            return null;
        }
        $value = filter_var($query[$key], \FILTER_VALIDATE_INT);

        return false === $value ? null : $value;
    }
}
