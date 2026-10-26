<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\DataTable;

/**
 * Splits a list URL query into view state (sort, page size, columns) and the rest,
 * so filter, search and reset links can keep how the table looks.
 */
final class DataTableQueryState
{
    /** @var list<string> */
    public const array VIEW_KEYS = ['sortBy', 'orderBy', 'limit', 'columns', 'columnOrder'];

    /** @var list<string> */
    public const array PAGINATION_KEYS = ['page', 'cursor', 'after', 'before'];

    /**
     * Scalar, non-empty query values without pagination and without the omitted keys.
     *
     * @param array<array-key, mixed> $query
     * @param list<string>            $omit
     *
     * @return array<string, string>
     */
    public static function scalarFields(array $query, array $omit = []): array
    {
        $fields = [];
        foreach ($query as $key => $value) {
            if (!\is_string($key) || '' === $key) {
                continue;
            }
            if (\in_array($key, self::PAGINATION_KEYS, true) || \in_array($key, $omit, true)) {
                continue;
            }
            if (!\is_scalar($value)) {
                continue;
            }
            $string = (string) $value;
            if ('' === $string) {
                continue;
            }
            $fields[$key] = $string;
        }

        return $fields;
    }

    /**
     * Only the view state of the query (sort, page size, columns, column order).
     *
     * @param array<array-key, mixed> $query
     *
     * @return array<string, string>
     */
    public static function viewFields(array $query): array
    {
        return array_intersect_key(
            self::scalarFields($query),
            array_fill_keys(self::VIEW_KEYS, true),
        );
    }
}
