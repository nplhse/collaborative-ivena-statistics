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
            self::sortable('dispatchArea', 'label.dispatch_area', 'text', ['property' => 'dispatchArea']),
            self::sortable('state', 'label.state', 'text', ['property' => 'state']),
            self::sortable('location', 'label.location', 'badge', ['property' => 'location', 'badgePalette' => 'hospital_location', 'nowrap' => true]),
            self::sortable('tier', 'label.tier', 'badge', ['property' => 'tier', 'badgePalette' => 'hospital_tier', 'nowrap' => true]),
            self::sortable('size', 'label.size', 'badge', ['property' => 'size', 'badgePalette' => 'hospital_size', 'numberProperty' => 'beds', 'nowrap' => true]),
            self::sortable('beds', 'label.hospital_beds', 'number', ['property' => 'beds', 'align' => 'end', 'visible' => false, 'format' => ['decimals' => 0]]),
            self::sortable('postalCode', 'label.address.postal_code', 'text', ['property' => 'address.postalCode', 'visible' => false, 'nowrap' => true]),
            self::sortable('city', 'label.address.city', 'text', ['property' => 'address.city', 'visible' => false]),
            self::sortable('participatingSince', 'label.participating_since', 'datetime', ['property' => 'participatingSince', 'visible' => false, 'format' => 'd.m.Y', 'nowrap' => true]),
            self::sortable('lastChange', 'label.last_change', 'custom', ['cellTemplate' => '@Allocation/hospitals/_cell_last_change.html.twig', 'nowrap' => true]),
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
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function sortable(string $key, string $label, string $type, array $config): array
    {
        return [
            'key' => $key,
            'type' => $type,
            'label' => $label,
            'sortable' => true,
            'sortKey' => $key,
            'configurable' => true,
            ...$config,
        ];
    }
}
