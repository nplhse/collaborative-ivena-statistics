<?php

declare(strict_types=1);

namespace App\Import\UI\Twig;

use App\Shared\Application\DataTable\DataTablePreferenceDefinitionInterface;
use App\Shared\Application\DataTable\DataTablePreferenceSchema;
use App\Shared\UI\Twig\DataTable\DataTableColumns;

/**
 * Columns of the import list. The sort keys are the single source for the query DTO and the ordering.
 */
final class ImportListColumns implements DataTablePreferenceDefinitionInterface
{
    public const string PREFERENCE_KEY = 'import.list';

    /** @var list<string> */
    public const array SORT_KEYS = [
        'type',
        'name',
        'status',
        'hospital',
        'createdAt',
        'lastChange',
        'rowCount',
        'rowsPassed',
        'rowsRejected',
        'rowsDeduplicated',
        'fileSize',
        'runTime',
        'runCount',
        'exportPeriod',
    ];

    public const string DEFAULT_SORT_BY = 'createdAt';

    public const string DEFAULT_ORDER_BY = 'desc';

    /**
     * @return list<array<string, mixed>>
     */
    public static function columns(): array
    {
        return [
            DataTableColumns::sortable('type', 'label.type', 'badge', ['property' => 'type', 'badgePalette' => 'import_type', 'nowrap' => true]),
            DataTableColumns::sortable('name', 'label.name', 'link', [
                'property' => 'name',
                'required' => true,
                'configurable' => false,
                'route' => 'app_import_show',
                'routeParams' => ['id' => 'id'],
            ]),
            DataTableColumns::sortable('status', 'label.status', 'badge', ['property' => 'status', 'badgePalette' => 'import_status', 'nowrap' => true]),
            DataTableColumns::sortable('hospital', 'label.hospital', 'link', [
                'property' => 'hospital',
                'route' => 'app_explore_hospital_show',
                'routeParams' => ['publicId' => 'hospital.publicId'],
            ]),
            self::count('rowCount', 'import.field.rowCount'),
            self::count('rowsPassed', 'import.field.rowsPassed'),
            self::count('rowsRejected', 'import.field.rowsRejected'),
            self::count('rowsDeduplicated', 'import.field.rowsDeduplicated'),
            DataTableColumns::sortable('fileSize', 'import.field.fileSize', 'custom', self::hidden([
                'align' => 'end',
                'cellTemplate' => '@Import/data_table/_cell_file_size.html.twig',
            ])),
            DataTableColumns::sortable('runTime', 'import.field.runTime', 'custom', self::hidden([
                'align' => 'end',
                'cellTemplate' => '@Import/data_table/_cell_run_time.html.twig',
            ])),
            self::count('runCount', 'import.field.runCount'),
            DataTableColumns::sortable('exportPeriod', 'import.field.exportPeriod', 'custom', self::hidden([
                'cellTemplate' => '@Import/data_table/_cell_export_period.html.twig',
            ])),
            DataTableColumns::sortable('createdAt', 'label.created_at', 'datetime', ['property' => 'createdAt', 'nowrap' => true]),
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
            defaultSortBy: self::DEFAULT_SORT_BY,
            defaultOrderBy: self::DEFAULT_ORDER_BY,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function count(string $key, string $label): array
    {
        return DataTableColumns::sortable($key, $label, 'number', self::hidden([
            'property' => $key,
            'align' => 'end',
            'format' => ['decimals' => 0],
        ]));
    }

    /**
     * Optional import columns live in the import domain and are hidden by default.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function hidden(array $config): array
    {
        return ['labelDomain' => 'import', 'visible' => false, ...$config];
    }
}
