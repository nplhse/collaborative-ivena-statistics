<?php

declare(strict_types=1);

namespace App\Service\Statistics;

/**
 * Defines grouped presets for the AgeChart.
 *
 * Each preset returns a list of metric descriptors:
 *   - name: series display name
 *   - col:  column name in agg_allocations_age_buckets to read (JSONB bucket)
 */
final class AgeMetricPresets
{
    /**
     * @return list<array{name:string,col:string}>
     */
    public static function metricsFor(string $preset): array
    {
        return match ($preset) {
            'gender' => [
                ['name' => 'Male',   'col' => 'gender_m'],
                ['name' => 'Female', 'col' => 'gender_w'],
                ['name' => 'Diverse', 'col' => 'gender_d'],
            ],
            'urgency' => [
                ['name' => 'Urgency 1', 'col' => 'urg_1'],
                ['name' => 'Urgency 2', 'col' => 'urg_2'],
                ['name' => 'Urgency 3', 'col' => 'urg_3'],
            ],
            'clinical' => [
                ['name' => 'Ventilated',     'col' => 'is_ventilated'],
                ['name' => 'CPR',            'col' => 'is_cpr'],
                ['name' => 'Shock',          'col' => 'is_shock'],
                ['name' => 'Pregnant',       'col' => 'is_pregnant'],
                ['name' => 'With Physician', 'col' => 'with_physician'],
                ['name' => 'Infectious',     'col' => 'infectious'],
            ],
            'resources' => [
                ['name' => 'Cathlab required', 'col' => 'cathlab_required'],
                ['name' => 'Resus required',  'col' => 'resus_required'],
            ],
            default => [ // 'total'
                ['name' => 'Total', 'col' => 'total'],
            ],
        };
    }

    /**
     * Used to render preset pickers in templates (optional helper).
     *
     * @return list<array{value:string,label:string}>
     */
    public static function all(): array
    {
        return [
            ['value' => 'total',    'label' => 'Total'],
            ['value' => 'gender',   'label' => 'Gender'],
            ['value' => 'urgency',  'label' => 'Urgency'],
            ['value' => 'clinical', 'label' => 'Clinical'],
            ['value' => 'resources', 'label' => 'Resources'],
        ];
    }
}
