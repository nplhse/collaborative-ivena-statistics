<?php

declare(strict_types=1);

namespace App\Allocation\UI\Twig;

use App\Allocation\Application\ListExtension\AllocationListExtensionRegistry;
use App\Shared\Application\DataTable\DataTablePreferenceDefinitionInterface;
use App\Shared\Application\DataTable\DataTablePreferenceSchema;
use App\Shared\UI\Twig\DataTable\DataTableColumns;
use App\User\Domain\Entity\User;

/**
 * Columns of the Explore allocation list. Sorting stays limited to the keyset keys
 * (arrival time and age); the optional columns only change what is shown.
 */
final readonly class AllocationListColumns implements DataTablePreferenceDefinitionInterface
{
    public const string PREFERENCE_KEY = 'explore.allocations';

    /** @var list<string> */
    public const array SORT_KEYS = ['arrivalAt', 'age'];

    /**
     * Optional columns that need an extra join; the list query only joins them when they are visible.
     *
     * @var list<string>
     */
    public const array JOINED_COLUMNS = ['speciality', 'department', 'assignment', 'occasion'];

    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private ?AllocationListExtensionRegistry $extensions = null,
    ) {
    }

    /**
     * The columns the user can see: the base columns plus the columns of the list extensions available to the
     * user (#1203), before the row action.
     *
     * @return list<array<string, mixed>>
     */
    public function columnsFor(?User $user): array
    {
        return $this->withExtensionColumns($this->extensions?->columnsFor($user) ?? []);
    }

    /**
     * Keys of the extension columns available to the user.
     *
     * @return list<string>
     */
    public function extensionColumnKeys(?User $user): array
    {
        return array_keys($this->extensions?->availableFor($user) ?? []);
    }

    /**
     * @param list<array<string, mixed>> $extensionColumns
     *
     * @return list<array<string, mixed>>
     */
    private function withExtensionColumns(array $extensionColumns): array
    {
        $columns = [];
        $actions = [];
        foreach (self::columns() as $column) {
            if ('actions' === ($column['key'] ?? null)) {
                $actions[] = $column;
            } else {
                $columns[] = $column;
            }
        }

        return [...$columns, ...$extensionColumns, ...$actions];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function columns(): array
    {
        return [
            DataTableColumns::sortable('timestamps', 'label.timestamps', 'custom', [
                'sortKey' => 'arrivalAt',
                'required' => true,
                'configurable' => false,
                'cellTemplate' => '@Allocation/allocations/_cell_timestamps.html.twig',
                'mobileLabel' => false,
            ]),
            DataTableColumns::sortable('age', 'label.age', 'custom', ['cellTemplate' => '@Allocation/allocations/_cell_age.html.twig']),
            DataTableColumns::configurable('hospital', 'label.hospital', 'custom', ['cellTemplate' => '@Allocation/allocations/_cell_hospital.html.twig']),
            DataTableColumns::configurable('indication', 'label.indication', 'custom', ['cellTemplate' => '@Allocation/allocations/_cell_indication.html.twig']),
            DataTableColumns::configurable('urgency', 'label.urgency', 'badge', ['property' => 'urgency', 'badgePalette' => 'allocation_urgency', 'nowrap' => true]),
            DataTableColumns::configurable('properties', 'label.properties', 'custom', ['cellTemplate' => '@Allocation/allocations/_cell_properties.html.twig']),
            DataTableColumns::configurable('gender', 'field.gender', 'custom', ['visible' => false, 'cellTemplate' => '@Allocation/allocations/_cell_gender.html.twig']),
            DataTableColumns::configurable('transportType', 'field.transportType', 'custom', ['visible' => false, 'cellTemplate' => '@Allocation/allocations/_cell_transport_type.html.twig']),
            DataTableColumns::configurable('speciality', 'label.speciality', 'text', ['property' => 'speciality', 'visible' => false]),
            DataTableColumns::configurable('department', 'label.department', 'text', ['property' => 'department', 'visible' => false]),
            DataTableColumns::configurable('assignment', 'label.assignment', 'text', ['property' => 'assignment', 'visible' => false]),
            DataTableColumns::configurable('occasion', 'label.occasion', 'text', ['property' => 'occasion', 'visible' => false]),
            DataTableColumns::configurable('secondaryTransport', 'label.secondary_transport', 'text', ['property' => 'secondaryTransport', 'visible' => false]),
            // Row action stays fixed at the end and is not part of the picker.
            [
                'key' => 'actions',
                'type' => 'actions',
                'label' => '',
                'route' => 'app_explore_allocation_show',
                'routeParams' => ['publicId' => 'publicId'],
            ],
        ];
    }

    #[\Override]
    public function preferenceSchema(): DataTablePreferenceSchema
    {
        // Every extension column is part of the schema, so a saved choice survives; columnsFor() decides who sees it.
        return DataTablePreferenceSchema::fromColumns(
            self::PREFERENCE_KEY,
            $this->withExtensionColumns($this->extensions?->allColumns() ?? []),
            sortKeys: self::SORT_KEYS,
            defaultSortBy: 'arrivalAt',
            defaultOrderBy: 'desc',
            defaultPageSize: 50,
        );
    }
}
