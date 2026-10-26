<?php

declare(strict_types=1);

namespace App\Allocation\UI\Twig;

use App\Shared\Application\DataTable\DataTablePreferenceDefinitionInterface;
use App\Shared\Application\DataTable\DataTablePreferenceSchema;
use App\Shared\UI\Twig\DataTable\DataTableColumns;

/**
 * Columns of the raw tab of the Explore indication list.
 */
final class IndicationRawListColumns implements DataTablePreferenceDefinitionInterface
{
    public const string PREFERENCE_KEY = 'explore.indications.raw';

    /** @var list<string> */
    public const array SORT_KEYS = [
        'code',
        'name',
        'reviewStatus',
        'occurrence',
        'reviewedAt',
        'firstMatchedAt',
        'createdAt',
        'lastChange',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public static function columns(): array
    {
        return [
            DataTableColumns::sortable('code', 'label.indication.code', 'badge', ['property' => 'code', 'nowrap' => true]),
            DataTableColumns::sortable('name', 'label.name', 'custom', ['required' => true, 'configurable' => false, 'cellTemplate' => '@Allocation/indications/_cell_raw_name.html.twig']),
            DataTableColumns::sortable('reviewStatus', 'label.indication.review_status', 'custom', ['cellTemplate' => '@Allocation/indications/_cell_review_status.html.twig', 'nowrap' => true]),
            DataTableColumns::configurable('normalized', 'label.normalized', 'custom', ['cellTemplate' => '@Allocation/indications/_cell_normalized_target.html.twig']),
            DataTableColumns::sortable('occurrence', 'label.indication.occurrences', 'custom', ['visible' => false, 'align' => 'end', 'cellTemplate' => '@Allocation/indications/_cell_occurrence.html.twig']),
            DataTableColumns::sortable('reviewedAt', 'label.indication.reviewed_at', 'datetime', ['property' => 'reviewedAt', 'visible' => false, 'nowrap' => true]),
            DataTableColumns::configurable('reviewedBy', 'label.indication.reviewed_by', 'user', ['property' => 'reviewedBy', 'visible' => false]),
            DataTableColumns::sortable('firstMatchedAt', 'label.indication.first_matched_at', 'datetime', ['property' => 'firstMatchedAt', 'visible' => false, 'nowrap' => true]),
            DataTableColumns::sortable('createdAt', 'label.created_at', 'datetime', ['property' => 'createdAt', 'visible' => false, 'nowrap' => true]),
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
