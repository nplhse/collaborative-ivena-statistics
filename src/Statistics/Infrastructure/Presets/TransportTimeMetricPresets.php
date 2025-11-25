<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Presets;

/**
 * Defines grouped presets for the TransportTimeChart.
 *
 * Each preset returns a list of metric descriptors:
 *   - name: display name for the series
 *   - id:   metric id in TransportTimeStatsView rows
 */
final class TransportTimeMetricPresets
{
    /**
     * @return list<array{name:string,id:string}>
     */
    public static function metricsFor(string $preset): array
    {
        return match ($preset) {
            'gender' => [
                ['name' => 'Male',   'id' => 'gender_m'],
                ['name' => 'Female', 'id' => 'gender_w'],
                ['name' => 'Diverse', 'id' => 'gender_d'],
            ],
            'urgency' => [
                ['name' => 'Urgency 1', 'id' => 'urg_1'],
                ['name' => 'Urgency 2', 'id' => 'urg_2'],
                ['name' => 'Urgency 3', 'id' => 'urg_3'],
            ],
            'transport' => [
                ['name' => 'Ground transport', 'id' => 'transport_ground'],
                ['name' => 'Air transport',    'id' => 'transport_air'],
            ],
            'resources' => [
                ['name' => 'Resus required', 'id' => 'resus_req'],
                ['name' => 'Cathlab required', 'id' => 'cathlab_req'],
            ],
            default => [ // 'total'
                ['name' => 'Total',          'id' => 'total'],
                ['name' => 'With physician', 'id' => 'with_physician'],
            ],
        };
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @return list<array{value:string,label:string}>
     */
    public static function all(): array
    {
        return [
            ['value' => 'total',     'label' => 'Total'],
            ['value' => 'gender',    'label' => 'Gender'],
            ['value' => 'urgency',   'label' => 'Urgency'],
            ['value' => 'transport', 'label' => 'Transport modes'],
            ['value' => 'resources', 'label' => 'Resources'],
        ];
    }
}
