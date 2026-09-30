<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\UI\Twig;

use App\Shared\Application\DataTable\DataTablePreferenceDefinitionInterface;
use App\Shared\Application\DataTable\DataTablePreferenceSchema;

final class ClosureIntervalTableColumns implements DataTablePreferenceDefinitionInterface
{
    public const string PREFERENCE_KEY = 'statistics.closure_analytics.intervals';

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
                'sortable' => true,
                'required' => true,
                'nowrap' => true,
                'cellTemplate' => '@Statistics/closure_analytics/data_table/_interval_start.html.twig',
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
            ],
            [
                'key' => 'hospital',
                'label' => 'stats.closure.table.hospital',
                'labelDomain' => $domain,
                'property' => 'hospitalName',
                'sortable' => true,
                'configurable' => true,
                'nowrap' => true,
            ],
            [
                'key' => 'event',
                'label' => 'stats.closure.events.event',
                'labelDomain' => $domain,
                'type' => 'custom',
                'property' => 'eventType',
                'required' => true,
                'cellTemplate' => '@Statistics/closure_analytics/data_table/_interval_event.html.twig',
            ],
            [
                'key' => 'speciality',
                'label' => 'stats.closure.table.speciality',
                'labelDomain' => $domain,
                'property' => 'specialityName',
                'sortable' => true,
                'configurable' => true,
            ],
            [
                'key' => 'department',
                'label' => 'stats.closure.table.department',
                'labelDomain' => $domain,
                'property' => 'departmentName',
                'sortable' => true,
                'configurable' => true,
            ],
            self::translatedBadge('careLevel', 'label.urgency', 'messages', 'careLevel'),
            [
                'key' => 'durationMinutes',
                'label' => 'stats.closure.table.duration',
                'labelDomain' => $domain,
                'type' => 'custom',
                'sortable' => true,
                'required' => true,
                'align' => 'end',
                'nowrap' => true,
                'cellTemplate' => '@Statistics/closure_analytics/data_table/_duration.html.twig',
            ],
            self::translatedBadge('reason', 'stats.closure.table.reason', $domain, 'reason'),
            [
                'key' => 'closureUnit',
                'label' => 'stats.closure.filter.closure_units',
                'labelDomain' => $domain,
                'sortable' => true,
                'configurable' => true,
                'emptyValue' => '—',
            ],
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

        return new DataTablePreferenceSchema(self::PREFERENCE_KEY, $order, $visible, $required);
    }

    /**
     * @return array<string, mixed>
     */
    private static function translatedBadge(string $key, string $label, string $labelDomain, string $property): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'labelDomain' => $labelDomain,
            'type' => 'custom',
            'property' => $property,
            'sortable' => true,
            'configurable' => true,
            'cellTemplate' => '@Statistics/closure_analytics/data_table/_interval_value.html.twig',
        ];
    }
}
