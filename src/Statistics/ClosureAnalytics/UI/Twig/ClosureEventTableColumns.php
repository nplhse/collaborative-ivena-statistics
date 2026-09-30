<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\UI\Twig;

use App\Shared\Application\DataTable\DataTablePreferenceDefinitionInterface;
use App\Shared\Application\DataTable\DataTablePreferenceSchema;

final class ClosureEventTableColumns implements DataTablePreferenceDefinitionInterface
{
    public const string PREFERENCE_KEY = 'statistics.closure_analytics.events';

    /**
     * @return list<array<string, mixed>>
     */
    public static function columns(): array
    {
        $domain = 'statistics';

        return [
            [
                'key' => 'startsAt',
                'label' => 'stats.closure.intervals.start',
                'labelDomain' => $domain,
                'type' => 'custom',
                'property' => 'startsAt',
                'sortable' => true,
                'sortKey' => 'startsAt',
                'required' => true,
                'nowrap' => true,
                'cellTemplate' => '@Statistics/closure_analytics/data_table/_start.html.twig',
            ],
            [
                'key' => 'endsAt',
                'label' => 'stats.closure.table.end',
                'labelDomain' => $domain,
                'type' => 'datetime',
                'sortable' => true,
                'configurable' => true,
                'format' => 'd.m.Y H:i',
                'timezone' => 'Europe/Berlin',
                'nowrap' => true,
                'emptyValue' => '—',
            ],
            [
                'key' => 'hospital',
                'label' => 'stats.closure.table.hospital',
                'labelDomain' => $domain,
                'property' => 'hospitalName',
                'sortable' => true,
                'sortKey' => 'hospital',
                'configurable' => true,
                'nowrap' => true,
            ],
            [
                'key' => 'event',
                'label' => 'stats.closure.events.event',
                'labelDomain' => $domain,
                'type' => 'custom',
                'property' => 'children',
                'sortable' => true,
                'sortKey' => 'event',
                'required' => true,
                'cellTemplate' => '@Statistics/closure_analytics/data_table/_event.html.twig',
            ],
            self::facet('specialities', 'stats.closure.table.speciality', $domain),
            [
                'key' => 'departments',
                'label' => 'stats.closure.table.departments',
                'labelDomain' => $domain,
                'type' => 'custom',
                'configurable' => true,
                'cellTemplate' => '@Statistics/closure_analytics/data_table/_departments.html.twig',
            ],
            [
                'key' => 'closureCount',
                'label' => 'stats.closure.table.count',
                'labelDomain' => $domain,
                'type' => 'number',
                'sortable' => true,
                'configurable' => true,
                'align' => 'end',
                'format' => ['decimals' => 0],
            ],
            self::facet('careLevels', 'label.urgency', 'messages'),
            [
                'key' => 'summedMinutes',
                'label' => 'stats.closure.table.sum',
                'labelDomain' => $domain,
                'type' => 'custom',
                'sortable' => true,
                'configurable' => true,
                'align' => 'end',
                'nowrap' => true,
                'cellTemplate' => '@Statistics/closure_analytics/data_table/_duration.html.twig',
            ],
            [
                'key' => 'actualMinutes',
                'label' => 'stats.closure.table.duration',
                'labelDomain' => $domain,
                'type' => 'custom',
                'sortable' => true,
                'required' => true,
                'align' => 'end',
                'nowrap' => true,
                'cellTemplate' => '@Statistics/closure_analytics/data_table/_duration.html.twig',
            ],
            self::facet('reasons', 'stats.closure.table.reason', $domain),
            self::facet('closureUnits', 'stats.closure.filter.closure_units', $domain),
        ];
    }

    #[\Override]
    public function preferenceSchema(): DataTablePreferenceSchema
    {
        $columns = self::columns();
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
            'configurable' => true,
            'cellTemplate' => '@Statistics/closure_analytics/data_table/_facet.html.twig',
        ];
    }
}
