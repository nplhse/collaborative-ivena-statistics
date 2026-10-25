<?php

declare(strict_types=1);

namespace App\Allocation\UI\Twig;

use App\Shared\UI\Twig\DataTable\DataTableColumnType;

/**
 * Small builders for the declarative column configs of the Explore lists.
 */
final class ListColumn
{
    /**
     * A column the user may show, hide and move.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function configurable(string $key, string $label, string $type, array $config = []): array
    {
        return [
            'key' => $key,
            'type' => $type,
            'label' => $label,
            'configurable' => true,
            ...$config,
        ];
    }

    /**
     * A configurable column that also sorts by its own key.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function sortable(string $key, string $label, string $type, array $config = []): array
    {
        return self::configurable($key, $label, $type, [
            'sortable' => true,
            'sortKey' => $key,
            ...$config,
        ]);
    }

    /**
     * "Last changed at" (sortable) and "last changed by", falling back to the creation values.
     *
     * @return list<array<string, mixed>>
     */
    public static function lastChange(): array
    {
        return [
            self::sortable('lastChange', 'label.last_changed_at', DataTableColumnType::DateTime->value, [
                'property' => 'updatedAt',
                'fallbackProperty' => 'createdAt',
                'nowrap' => true,
            ]),
            self::configurable('lastChangedBy', 'label.last_changed_by', DataTableColumnType::User->value, [
                'property' => 'updatedBy',
                'fallbackProperty' => 'createdBy',
            ]),
        ];
    }
}
