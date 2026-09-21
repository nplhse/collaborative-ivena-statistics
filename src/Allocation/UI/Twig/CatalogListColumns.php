<?php

declare(strict_types=1);

namespace App\Allocation\UI\Twig;

use App\Shared\UI\Twig\DataTable\DataTableColumn;
use App\Shared\UI\Twig\DataTable\DataTableColumnType;

final class CatalogListColumns
{
    /**
     * @param list<array<string, mixed>> $extra
     *
     * @return list<array<string, mixed>>
     */
    public static function standard(string $showRoute, array $extra = []): array
    {
        $nameWidth = [] === $extra ? 'col-8' : 'col-4';

        $columns = [
            [
                'key' => 'name',
                'type' => DataTableColumnType::Link->value,
                'label' => 'label.name',
                'property' => 'name',
                'sortable' => true,
                'sortKey' => 'name',
                'width' => $nameWidth,
                'route' => $showRoute,
                'routeParams' => ['publicId' => 'publicId'],
            ],
        ];

        foreach ($extra as $column) {
            $columns[] = $column;
        }

        $columns[] = [
            'key' => 'lastChange',
            'type' => DataTableColumnType::DateTime->value,
            'label' => 'label.last_changed_at',
            'property' => 'updatedAt',
            'fallbackProperty' => 'createdAt',
            'sortable' => true,
            'sortKey' => 'lastChange',
            'width' => 'col-2',
        ];
        $columns[] = [
            'key' => 'lastChangedBy',
            'type' => DataTableColumnType::User->value,
            'label' => 'label.last_changed_by',
            'property' => 'updatedBy',
            'fallbackProperty' => 'createdBy',
            'width' => 'col-2',
        ];

        return $columns;
    }

    /**
     * @param list<array<string, mixed>> $extra
     *
     * @return list<DataTableColumn>
     */
    public static function standardColumns(string $showRoute, array $extra = []): array
    {
        return array_map(
            static fn (array $column): DataTableColumn => DataTableColumn::fromArray($column),
            self::standard($showRoute, $extra),
        );
    }
}
