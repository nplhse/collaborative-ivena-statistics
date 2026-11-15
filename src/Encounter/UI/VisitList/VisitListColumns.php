<?php

declare(strict_types=1);

namespace App\Encounter\UI\VisitList;

use App\Encounter\Application\Contract\VisitListReaderInterface;
use App\Shared\Application\DataTable\DataTablePreferenceDefinitionInterface;
use App\Shared\Application\DataTable\DataTablePreferenceSchema;
use App\Shared\UI\Twig\DataTable\DataTableColumns;

/**
 * Columns of the visit list (#1178). The visit time in the first, required column links to the visit detail
 * page, so the list needs no action column; on narrow screens it is the card title.
 */
final class VisitListColumns implements DataTablePreferenceDefinitionInterface
{
    public const string PREFERENCE_KEY = 'explore.ed_visits';

    private const string CELL = '@Encounter/visit_list/_cell.html.twig';

    /**
     * @return list<array<string, mixed>>
     */
    public static function columns(): array
    {
        return [
            DataTableColumns::sortable('anchor', 'encounter.visit_list.column.anchor', 'custom', [
                'labelDomain' => 'encounter',
                'required' => true,
                'configurable' => false,
                'cellTemplate' => self::CELL,
                'mobileLabel' => false,
                'nowrap' => true,
            ]),
            DataTableColumns::sortable('age', 'encounter.visit_list.column.age', 'custom', ['labelDomain' => 'encounter', 'cellTemplate' => self::CELL]),
            DataTableColumns::configurable('route', 'encounter.visit_list.column.route', 'custom', ['labelDomain' => 'encounter', 'cellTemplate' => self::CELL]),
            DataTableColumns::configurable('triage', 'encounter.visit_list.column.triage', 'custom', ['labelDomain' => 'encounter', 'cellTemplate' => self::CELL]),
            DataTableColumns::configurable('disposition', 'encounter.visit_list.column.disposition', 'custom', ['labelDomain' => 'encounter', 'cellTemplate' => self::CELL]),
            DataTableColumns::configurable('link', 'encounter.visit_list.column.link', 'custom', ['labelDomain' => 'encounter', 'cellTemplate' => self::CELL]),
            DataTableColumns::configurable('referral', 'encounter.visit_list.column.referral', 'custom', ['labelDomain' => 'encounter', 'cellTemplate' => self::CELL, 'visible' => false]),
            DataTableColumns::configurable('caseStatus', 'encounter.visit_list.column.case_status', 'custom', ['labelDomain' => 'encounter', 'cellTemplate' => self::CELL, 'visible' => false]),
            DataTableColumns::configurable('department', 'encounter.visit_list.column.department', 'custom', ['labelDomain' => 'encounter', 'cellTemplate' => self::CELL, 'visible' => false]),
        ];
    }

    #[\Override]
    public function preferenceSchema(): DataTablePreferenceSchema
    {
        return DataTablePreferenceSchema::fromColumns(
            self::PREFERENCE_KEY,
            self::columns(),
            sortKeys: VisitListReaderInterface::SORT_KEYS,
            defaultSortBy: 'anchor',
            defaultOrderBy: 'desc',
            defaultPageSize: 50,
        );
    }
}
