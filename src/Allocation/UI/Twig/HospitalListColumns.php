<?php

declare(strict_types=1);

namespace App\Allocation\UI\Twig;

use App\Shared\Application\DataTable\DataTablePreferenceDefinitionInterface;
use App\Shared\Application\DataTable\DataTablePreferenceSchema;

/**
 * Columns of the Explore hospital list. The sort keys are the single source for the
 * query DTO and the repository ordering.
 */
final class HospitalListColumns implements DataTablePreferenceDefinitionInterface
{
    public const string PREFERENCE_KEY = 'explore.hospitals';

    /** @var list<string> */
    public const array SORT_KEYS = [
        'name',
        'dispatchArea',
        'state',
        'location',
        'tier',
        'size',
        'beds',
        'postalCode',
        'city',
        'participatingSince',
        'lastChange',
    ];

    public const string DEFAULT_SORT_BY = 'name';

    public const string DEFAULT_ORDER_BY = 'asc';

    /**
     * @return list<array<string, mixed>>
     */
    public static function columns(): array
    {
        return [
            ['key' => 'participating', 'type' => 'boolean', 'label' => '', 'property' => 'participating'],
            [
                'key' => 'name',
                'type' => 'link',
                'label' => 'label.name',
                'property' => 'name',
                'sortable' => true,
                'sortKey' => 'name',
                'required' => true,
                'route' => 'app_explore_hospital_show',
                'routeParams' => ['publicId' => 'publicId'],
            ],
            ListColumn::sortable('dispatchArea', 'label.dispatch_area', 'text', ['property' => 'dispatchArea']),
            ListColumn::sortable('state', 'label.state', 'text', ['property' => 'state']),
            ListColumn::sortable('location', 'label.location', 'badge', ['property' => 'location', 'badgePalette' => 'hospital_location', 'nowrap' => true]),
            ListColumn::sortable('tier', 'label.tier', 'badge', ['property' => 'tier', 'badgePalette' => 'hospital_tier', 'nowrap' => true]),
            ListColumn::sortable('size', 'label.size', 'badge', ['property' => 'size', 'badgePalette' => 'hospital_size', 'numberProperty' => 'beds', 'nowrap' => true]),
            ListColumn::sortable('beds', 'label.hospital_beds', 'number', ['property' => 'beds', 'align' => 'end', 'visible' => false, 'format' => ['decimals' => 0]]),
            ListColumn::sortable('postalCode', 'label.address.postal_code', 'text', ['property' => 'address.postalCode', 'visible' => false, 'nowrap' => true]),
            ListColumn::sortable('city', 'label.address.city', 'text', ['property' => 'address.city', 'visible' => false]),
            ListColumn::sortable('participatingSince', 'label.participating_since', 'datetime', ['property' => 'participatingSince', 'visible' => false, 'format' => 'd.m.Y', 'nowrap' => true]),
            ListColumn::sortable('lastChange', 'label.last_change', 'custom', ['cellTemplate' => '@Allocation/hospitals/_cell_last_change.html.twig', 'nowrap' => true]),
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
}
