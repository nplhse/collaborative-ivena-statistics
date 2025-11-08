<?php

namespace App\Service\Statistics;

final class HourlyMetricPresets
{
    /** @return list<string> */
    public static function metricsFor(string $preset): array
    {
        return match ($preset) {
            'total' => ['total'],
            'gender' => ['gender_m', 'gender_w', 'gender_d'],
            'urgency' => ['urg_1', 'urg_2', 'urg_3'],
            'clinical' => ['is_ventilated', 'is_cpr', 'is_shock', 'is_pregnant', 'with_physician', 'infectious'],
            'resources' => ['cathlab', 'resus'],
            default => ['total'],
        };
    }

    /** @return list<array{value:string,label:string}> */
    public static function all(): array
    {
        return [
            ['value' => 'total', 'label' => 'Total'],
            ['value' => 'gender', 'label' => 'Gender'],
            ['value' => 'urgency', 'label' => 'Urgency'],
            ['value' => 'clinical', 'label' => 'Clinical'],
            ['value' => 'resources', 'label' => 'Resources'],
        ];
    }
}
