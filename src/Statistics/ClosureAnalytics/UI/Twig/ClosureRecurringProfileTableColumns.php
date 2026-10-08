<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\UI\Twig;

use App\Shared\Application\DataTable\DataTablePreferenceDefinitionInterface;
use App\Shared\Application\DataTable\DataTablePreferenceSchema;

final class ClosureRecurringProfileTableColumns implements DataTablePreferenceDefinitionInterface
{
    public const string PREFERENCE_KEY = 'statistics.closure_analytics.recurring_profiles';

    /**
     * @return list<array<string, mixed>>
     */
    public static function columns(bool $showHospital): array
    {
        $domain = 'statistics';
        $columns = [
            [
                'key' => 'configuration',
                'label' => 'stats.closure.profile.recurring.configuration',
                'labelDomain' => $domain,
                'type' => 'custom',
                'sortable' => true,
                'sortKey' => 'configuration',
                'required' => true,
                'cellTemplate' => '@Statistics/closure_analytics/data_table/_recurring_profile_config.html.twig',
            ],
            [
                'key' => 'composition',
                'label' => 'stats.closure.profile.recurring.composition',
                'labelDomain' => $domain,
                'type' => 'custom',
                'configurable' => true,
                'cellTemplate' => '@Statistics/closure_analytics/data_table/_recurring_profile_members_preview.html.twig',
            ],
            [
                'key' => 'specialities',
                'label' => 'stats.closure.table.speciality',
                'labelDomain' => $domain,
                'type' => 'custom',
                'property' => 'specialities',
                'configurable' => true,
                'cellTemplate' => '@Statistics/closure_analytics/data_table/_recurring_profile_name_facet.html.twig',
            ],
            [
                'key' => 'departments',
                'label' => 'stats.closure.table.departments',
                'labelDomain' => $domain,
                'type' => 'custom',
                'property' => 'departments',
                'configurable' => true,
                'cellTemplate' => '@Statistics/closure_analytics/data_table/_recurring_profile_name_facet.html.twig',
            ],
            self::facet('careLevels', 'label.urgency', 'messages'),
            self::facet('reasons', 'stats.closure.table.reason', $domain),
            [
                'key' => 'actions',
                'label' => 'stats.closure.profile.recurring.actions',
                'labelDomain' => $domain,
                'type' => 'custom',
                'configurable' => false,
                'nowrap' => true,
                'cellTemplate' => '@Statistics/closure_analytics/data_table/_recurring_profile_actions.html.twig',
            ],
        ];

        if ($showHospital) {
            array_splice($columns, 6, 0, [[
                'key' => 'hospital',
                'label' => 'stats.closure.table.hospital',
                'labelDomain' => $domain,
                'property' => 'hospitalName',
                'sortable' => true,
                'sortKey' => 'hospital',
                'configurable' => true,
                'nowrap' => true,
            ]]);
        }

        return $columns;
    }

    #[\Override]
    public function preferenceSchema(): DataTablePreferenceSchema
    {
        $columns = self::columns(true);
        $order = [];
        $visible = [];
        $required = [];
        foreach ($columns as $column) {
            $key = $column['key'] ?? null;
            if (!\is_string($key)) {
                continue;
            }
            $order[] = $key;
            $isVisible = false !== ($column['visible'] ?? true);
            $isRequired = true === ($column['required'] ?? false);
            if ($isVisible || $isRequired) {
                $visible[] = $key;
            }
            if ($isRequired) {
                $required[] = $key;
            }
        }

        return new DataTablePreferenceSchema(
            self::PREFERENCE_KEY,
            $order,
            $visible,
            $required,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function facet(string $key, string $label, string $domain): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'labelDomain' => $domain,
            'type' => 'custom',
            'property' => $key,
            'configurable' => true,
            'cellTemplate' => '@Statistics/closure_analytics/data_table/_facet.html.twig',
        ];
    }
}
