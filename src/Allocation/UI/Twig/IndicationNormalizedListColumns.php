<?php

declare(strict_types=1);

namespace App\Allocation\UI\Twig;

use App\Shared\Application\DataTable\DataTablePreferenceDefinitionInterface;
use App\Shared\Application\DataTable\DataTablePreferenceSchema;
use App\Shared\UI\Twig\DataTable\DataTableColumns;

/**
 * Columns of the normalized tab of the Explore indication list.
 */
final class IndicationNormalizedListColumns implements DataTablePreferenceDefinitionInterface
{
    public const string PREFERENCE_KEY = 'explore.indications.normalized';

    /** @var list<string> */
    public const array SORT_KEYS = ['code', 'name', 'rawCount', 'occurrence', 'lastChange'];

    /**
     * @return list<array<string, mixed>>
     */
    public static function columns(): array
    {
        return [
            DataTableColumns::sortable('code', 'label.indication.code', 'badge', ['property' => 'code', 'nowrap' => true]),
            DataTableColumns::sortable('name', 'label.name', 'custom', ['required' => true, 'configurable' => false, 'cellTemplate' => '@Allocation/indications/_cell_normalized_name.html.twig']),
            DataTableColumns::configurable('note', 'catalog.field.note', 'text', ['property' => 'note', 'labelDomain' => 'allocation', 'visible' => false]),
            DataTableColumns::configurable('groups', 'label.indication.groups', 'custom', ['visible' => false, 'cellTemplate' => '@Allocation/indications/_cell_normalized_groups.html.twig']),
            DataTableColumns::sortable('rawCount', 'label.indication.raw_count', 'custom', ['visible' => false, 'align' => 'end', 'cellTemplate' => '@Allocation/indications/_cell_normalized_raw_count.html.twig']),
            DataTableColumns::sortable('occurrence', 'label.indication.occurrences', 'custom', ['visible' => false, 'align' => 'end', 'cellTemplate' => '@Allocation/indications/_cell_occurrence.html.twig']),
            ...DataTableColumns::lastChange(),
        ];
    }

    #[\Override]
    public function preferenceSchema(): DataTablePreferenceSchema
    {
        return DataTablePreferenceSchema::fromColumns(
            self::PREFERENCE_KEY,
            self::columns(),
            sortKeys: self::SORT_KEYS,
            defaultSortBy: 'code',
            defaultOrderBy: 'asc',
        );
    }
}
