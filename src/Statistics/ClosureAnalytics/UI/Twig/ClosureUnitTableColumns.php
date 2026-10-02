<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\UI\Twig;

use App\Shared\Application\DataTable\DataTablePreferenceDefinitionInterface;
use App\Shared\Application\DataTable\DataTablePreferenceSchema;

final class ClosureUnitTableColumns implements DataTablePreferenceDefinitionInterface
{
    public const string PREFERENCE_KEY = 'statistics.closure_analytics.units';

    /** @var list<string> */
    private const array SORT_KEYS = ['hospital', 'name', 'share', 'eventCount', 'duration'];

    /**
     * @return list<array<string, mixed>>
     */
    public static function columns(): array
    {
        $domain = 'statistics';

        return [
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
                'key' => 'name',
                'label' => 'label.name',
                'labelDomain' => 'messages',
                'type' => 'custom',
                'property' => 'name',
                'sortable' => true,
                'sortKey' => 'name',
                'required' => true,
                'cellTemplate' => '@Statistics/closure_analytics/data_table/_unit_name.html.twig',
            ],
            [
                'key' => 'share',
                'label' => 'stats.closure.breakdown.share',
                'labelDomain' => $domain,
                'type' => 'custom',
                'property' => 'sharePercent',
                'sortable' => true,
                'sortKey' => 'share',
                'configurable' => true,
                'align' => 'end',
                'nowrap' => true,
                'cellTemplate' => '@Statistics/closure_analytics/data_table/_unit_share.html.twig',
            ],
            [
                'key' => 'eventCount',
                'label' => 'stats.closure.units.events',
                'labelDomain' => $domain,
                'type' => 'number',
                'property' => 'eventCount',
                'sortable' => true,
                'sortKey' => 'eventCount',
                'configurable' => true,
                'align' => 'end',
                'nowrap' => true,
                'format' => ['decimals' => 0],
            ],
            [
                'key' => 'duration',
                'label' => 'stats.closure.table.duration',
                'labelDomain' => $domain,
                'type' => 'custom',
                'property' => 'actualMinutes',
                'sortable' => true,
                'sortKey' => 'duration',
                'configurable' => true,
                'align' => 'end',
                'nowrap' => true,
                'cellTemplate' => '@Statistics/closure_analytics/data_table/_duration.html.twig',
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

        return new DataTablePreferenceSchema(
            self::PREFERENCE_KEY,
            $order,
            $visible,
            $required,
            sortKeys: self::SORT_KEYS,
            defaultSortBy: 'duration',
            defaultOrderBy: 'desc',
        );
    }
}
