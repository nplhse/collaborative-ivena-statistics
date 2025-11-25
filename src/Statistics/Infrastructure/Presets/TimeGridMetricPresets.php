<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Presets;

/**
 * Produces row definitions for the HospitalTimeGrid component.
 * Each row maps to a DashboardPanelView property via "key" and a "format".
 */
final class TimeGridMetricPresets
{
    /**
     * Preset options for UIs (value + human label).
     *
     * @return list<array{value:string,label:string}>
     */
    public static function all(): array
    {
        return [
            ['value' => 'default',   'label' => 'Default'],
            ['value' => 'gender',    'label' => 'Gender'],
            ['value' => 'urgency',   'label' => 'Urgency'],
            ['value' => 'clinical',  'label' => 'Clinical'],
            ['value' => 'resources', 'label' => 'Resources'],
            ['value' => 'all',       'label' => 'Everything'],
        ];
    }

    /**
     * Row definitions for a given preset.
     * Keys must match DashboardPanelView public properties.
     * Formats: "int" | "pct".
     *
     * @return list<array{label:string,key:string,format:"int"|"pct"}>
     */
    public static function rowsFor(string $preset): array
    {
        return match ($preset) {
            'gender' => self::blockGender(withTotal: true),
            'urgency' => self::blockUrgency(withTotal: true),
            'clinical' => self::blockClinical(withTotal: true),
            'resources' => self::blockResources(withTotal: true),
            'all' => self::blockAll(),
            default => self::blockDefault(withTotal: true),
        };
    }

    /** @return list<array{label:string,key:string,format:"int"|"pct"}> */
    private static function blockTotal(): array
    {
        return [
            ['label' => 'Total', 'key' => 'total', 'format' => 'int'],
        ];
    }

    /** @return list<array{label:string,key:string,format:"int"|"pct"}> */
    private static function blockGender(bool $withTotal = false): array
    {
        $rows = $withTotal ? self::blockTotal() : [];

        return array_merge($rows, [
            ['label' => 'Male',      'key' => 'genderM',  'format' => 'int'],
            ['label' => 'Male %',    'key' => 'pctMale',  'format' => 'pct'],
            ['label' => 'Female',    'key' => 'genderW',  'format' => 'int'],
            ['label' => 'Female %',  'key' => 'pctFemale', 'format' => 'pct'],
            ['label' => 'Diverse',   'key' => 'genderD',  'format' => 'int'],
            ['label' => 'Diverse %', 'key' => 'pctDiverse', 'format' => 'pct'],
        ]);
    }

    /** @return list<array{label:string,key:string,format:"int"|"pct"}> */
    private static function blockUrgency(bool $withTotal = false): array
    {
        $rows = $withTotal ? self::blockTotal() : [];

        return array_merge($rows, [
            ['label' => 'Urgency 1',   'key' => 'urg1',   'format' => 'int'],
            ['label' => 'Urgency 1 %', 'key' => 'pctUrg1', 'format' => 'pct'],
            ['label' => 'Urgency 2',   'key' => 'urg2',   'format' => 'int'],
            ['label' => 'Urgency 2 %', 'key' => 'pctUrg2', 'format' => 'pct'],
            ['label' => 'Urgency 3',   'key' => 'urg3',   'format' => 'int'],
            ['label' => 'Urgency 3 %', 'key' => 'pctUrg3', 'format' => 'pct'],
        ]);
    }

    /** @return list<array{label:string,key:string,format:"int"|"pct"}> */
    private static function blockClinical(bool $withTotal = false): array
    {
        $rows = $withTotal ? self::blockTotal() : [];

        return array_merge($rows, [
            ['label' => 'Ventilated',        'key' => 'isVentilated',   'format' => 'int'],
            ['label' => 'Ventilated %',      'key' => 'pctVentilated',  'format' => 'pct'],
            ['label' => 'CPR',               'key' => 'isCpr',          'format' => 'int'],
            ['label' => 'CPR %',             'key' => 'pctCpr',         'format' => 'pct'],
            ['label' => 'Shock',             'key' => 'isShock',        'format' => 'int'],
            ['label' => 'Shock %',           'key' => 'pctShock',       'format' => 'pct'],
            ['label' => 'Pregnant',          'key' => 'isPregnant',     'format' => 'int'],
            ['label' => 'Pregnant %',        'key' => 'pctPregnant',    'format' => 'pct'],
            ['label' => 'With physician',    'key' => 'withPhysician',  'format' => 'int'],
            ['label' => 'With physician %',  'key' => 'pctWithPhysician', 'format' => 'pct'],
            ['label' => 'Infectious',        'key' => 'infectious',     'format' => 'int'],
            ['label' => 'Infectious %',      'key' => 'pctInfectious',  'format' => 'pct'],
        ]);
    }

    /** @return list<array{label:string,key:string,format:"int"|"pct"}> */
    private static function blockResources(bool $withTotal = false): array
    {
        $rows = $withTotal ? self::blockTotal() : [];

        return array_merge($rows, [
            ['label' => 'Cathlab required',        'key' => 'cathlabRequired',  'format' => 'int'],
            ['label' => 'Cathlab required %',      'key' => 'pctCathlabRequired', 'format' => 'pct'],
            ['label' => 'Resus required',          'key' => 'resusRequired',    'format' => 'int'],
            ['label' => 'Resus required %',        'key' => 'pctResusRequired', 'format' => 'pct'],
        ]);
    }

    /** @return list<array{label:string,key:string,format:"int"|"pct"}> */
    private static function blockAll(): array
    {
        return array_merge(
            self::blockTotal(),
            self::blockGender(),
            self::blockUrgency(),
            self::blockClinical(),
            self::blockResources(),
        );
    }

    /** @return list<array{label:string,key:string,format:"int"|"pct"}> */
    private static function blockDefault(bool $withTotal = false): array
    {
        $rows = $withTotal ? self::blockTotal() : [];

        return array_merge($rows, [
            ['label' => 'Male',      'key' => 'genderM',  'format' => 'int'],
            ['label' => 'Female',    'key' => 'genderW',  'format' => 'int'],
            ['label' => 'Diverse',   'key' => 'genderD',  'format' => 'int'],
            ['label' => 'Urgency 1',   'key' => 'urg1',   'format' => 'int'],
            ['label' => 'Urgency 2',   'key' => 'urg2',   'format' => 'int'],
            ['label' => 'Urgency 3',   'key' => 'urg3',   'format' => 'int'],
            ['label' => 'Cathlab required',        'key' => 'cathlabRequired',  'format' => 'int'],
            ['label' => 'Resus required',          'key' => 'resusRequired',    'format' => 'int'],
            ['label' => 'Ventilated',        'key' => 'isVentilated',   'format' => 'int'],
            ['label' => 'CPR',               'key' => 'isCpr',          'format' => 'int'],
            ['label' => 'Shock',             'key' => 'isShock',        'format' => 'int'],
            ['label' => 'Pregnant',          'key' => 'isPregnant',     'format' => 'int'],
            ['label' => 'With physician',    'key' => 'withPhysician',  'format' => 'int'],
            ['label' => 'Infectious',        'key' => 'infectious',     'format' => 'int'],
        ]);
    }
}
