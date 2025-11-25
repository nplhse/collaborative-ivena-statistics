<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Service\Presets;

use App\Statistics\Infrastructure\Presets\TimeGridMetricPresets;
use PHPUnit\Framework\TestCase;

final class TimeGridMetricPresetsTest extends TestCase
{
    public function testAllReturnsExpectedPresetOptionsInOrder(): void
    {
        $expected = [
            ['value' => 'default',   'label' => 'Default'],
            ['value' => 'gender',    'label' => 'Gender'],
            ['value' => 'urgency',   'label' => 'Urgency'],
            ['value' => 'clinical',  'label' => 'Clinical'],
            ['value' => 'resources', 'label' => 'Resources'],
            ['value' => 'all',       'label' => 'Everything'],
        ];

        self::assertSame($expected, TimeGridMetricPresets::all());
    }

    public function testAllPresetValuesAreUnique(): void
    {
        $values = array_column(TimeGridMetricPresets::all(), 'value');

        self::assertCount(
            count(array_unique($values)),
            $values,
            'Preset values should be unique.'
        );
    }

    public function testRowsForUnknownPresetFallsBackToDefault(): void
    {
        $defaultRows = TimeGridMetricPresets::rowsFor('default');
        $unknownRows = TimeGridMetricPresets::rowsFor('some-nonsense');

        self::assertSame(
            $defaultRows,
            $unknownRows,
            'Unknown preset should fall back to the default preset.'
        );
    }

    public function testRowsForGenderPresetMatchesDefinition(): void
    {
        $rows = TimeGridMetricPresets::rowsFor('gender');

        $expected = [
            ['label' => 'Total',      'key' => 'total',       'format' => 'int'],
            ['label' => 'Male',       'key' => 'genderM',     'format' => 'int'],
            ['label' => 'Male %',     'key' => 'pctMale',     'format' => 'pct'],
            ['label' => 'Female',     'key' => 'genderW',     'format' => 'int'],
            ['label' => 'Female %',   'key' => 'pctFemale',   'format' => 'pct'],
            ['label' => 'Diverse',    'key' => 'genderD',     'format' => 'int'],
            ['label' => 'Diverse %',  'key' => 'pctDiverse',  'format' => 'pct'],
        ];

        self::assertSame($expected, $rows);
    }

    public function testRowsForUrgencyPresetMatchesDefinition(): void
    {
        $rows = TimeGridMetricPresets::rowsFor('urgency');

        $expected = [
            ['label' => 'Total',        'key' => 'total',     'format' => 'int'],
            ['label' => 'Urgency 1',    'key' => 'urg1',      'format' => 'int'],
            ['label' => 'Urgency 1 %',  'key' => 'pctUrg1',   'format' => 'pct'],
            ['label' => 'Urgency 2',    'key' => 'urg2',      'format' => 'int'],
            ['label' => 'Urgency 2 %',  'key' => 'pctUrg2',   'format' => 'pct'],
            ['label' => 'Urgency 3',    'key' => 'urg3',      'format' => 'int'],
            ['label' => 'Urgency 3 %',  'key' => 'pctUrg3',   'format' => 'pct'],
        ];

        self::assertSame($expected, $rows);
    }

    public function testRowsForClinicalPresetMatchesDefinition(): void
    {
        $rows = TimeGridMetricPresets::rowsFor('clinical');

        $expected = [
            ['label' => 'Total',              'key' => 'total',             'format' => 'int'],
            ['label' => 'Ventilated',         'key' => 'isVentilated',      'format' => 'int'],
            ['label' => 'Ventilated %',       'key' => 'pctVentilated',     'format' => 'pct'],
            ['label' => 'CPR',                'key' => 'isCpr',             'format' => 'int'],
            ['label' => 'CPR %',              'key' => 'pctCpr',            'format' => 'pct'],
            ['label' => 'Shock',              'key' => 'isShock',           'format' => 'int'],
            ['label' => 'Shock %',            'key' => 'pctShock',          'format' => 'pct'],
            ['label' => 'Pregnant',           'key' => 'isPregnant',        'format' => 'int'],
            ['label' => 'Pregnant %',         'key' => 'pctPregnant',       'format' => 'pct'],
            ['label' => 'With physician',     'key' => 'withPhysician',     'format' => 'int'],
            ['label' => 'With physician %',   'key' => 'pctWithPhysician',  'format' => 'pct'],
            ['label' => 'Infectious',         'key' => 'infectious',        'format' => 'int'],
            ['label' => 'Infectious %',       'key' => 'pctInfectious',     'format' => 'pct'],
        ];

        self::assertSame($expected, $rows);
    }

    public function testRowsForResourcesPresetMatchesDefinition(): void
    {
        $rows = TimeGridMetricPresets::rowsFor('resources');

        $expected = [
            ['label' => 'Total',                'key' => 'total',               'format' => 'int'],
            ['label' => 'Cathlab required',     'key' => 'cathlabRequired',     'format' => 'int'],
            ['label' => 'Cathlab required %',   'key' => 'pctCathlabRequired',  'format' => 'pct'],
            ['label' => 'Resus required',       'key' => 'resusRequired',       'format' => 'int'],
            ['label' => 'Resus required %',     'key' => 'pctResusRequired',    'format' => 'pct'],
        ];

        self::assertSame($expected, $rows);
    }

    public function testRowsForDefaultPresetMatchesDefinition(): void
    {
        $rows = TimeGridMetricPresets::rowsFor('default');

        $expected = [
            ['label' => 'Total',              'key' => 'total',          'format' => 'int'],
            ['label' => 'Male',               'key' => 'genderM',        'format' => 'int'],
            ['label' => 'Female',             'key' => 'genderW',        'format' => 'int'],
            ['label' => 'Diverse',            'key' => 'genderD',        'format' => 'int'],
            ['label' => 'Urgency 1',          'key' => 'urg1',           'format' => 'int'],
            ['label' => 'Urgency 2',          'key' => 'urg2',           'format' => 'int'],
            ['label' => 'Urgency 3',          'key' => 'urg3',           'format' => 'int'],
            ['label' => 'Cathlab required',   'key' => 'cathlabRequired', 'format' => 'int'],
            ['label' => 'Resus required',     'key' => 'resusRequired',  'format' => 'int'],
            ['label' => 'Ventilated',         'key' => 'isVentilated',   'format' => 'int'],
            ['label' => 'CPR',                'key' => 'isCpr',          'format' => 'int'],
            ['label' => 'Shock',              'key' => 'isShock',        'format' => 'int'],
            ['label' => 'Pregnant',           'key' => 'isPregnant',     'format' => 'int'],
            ['label' => 'With physician',     'key' => 'withPhysician',  'format' => 'int'],
            ['label' => 'Infectious',         'key' => 'infectious',     'format' => 'int'],
        ];

        self::assertSame($expected, $rows);
    }

    public function testRowsForAllPresetContainsTotalAndAllOtherMetricRows(): void
    {
        $rowsAll = TimeGridMetricPresets::rowsFor('all');

        self::assertNotEmpty($rowsAll);
        self::assertSame('Total', $rowsAll[0]['label']);
        self::assertSame('total', $rowsAll[0]['key']);
        self::assertSame('int', $rowsAll[0]['format']);

        $totalRows = array_values(array_filter($rowsAll, static fn (array $row): bool => 'total' === $row['key']));
        self::assertCount(1, $totalRows, 'Preset "all" should contain exactly one "total" row.');

        $presetsToCheck = ['gender', 'urgency', 'clinical', 'resources'];
        $allKeys = array_column($rowsAll, 'key');

        foreach ($presetsToCheck as $preset) {
            $rows = TimeGridMetricPresets::rowsFor($preset);
            foreach ($rows as $row) {
                if ('total' === $row['key']) {
                    continue;
                }

                self::assertContains(
                    $row['key'],
                    $allKeys,
                    sprintf('Preset "all" should contain key "%s" from preset "%s".', $row['key'], $preset)
                );
            }
        }
    }

    public function testAllPresetsUseOnlyIntOrPctFormats(): void
    {
        $presetValues = array_column(TimeGridMetricPresets::all(), 'value');
        $presetValues[] = 'some-nonsense';

        $seenFormats = [];

        foreach ($presetValues as $value) {
            foreach (TimeGridMetricPresets::rowsFor($value) as $row) {
                $format = $row['format'];
                $seenFormats[$format] = true;

                self::assertContains(
                    $format,
                    ['int', 'pct'],
                    sprintf('Unexpected format "%s" in preset "%s".', $format, $value)
                );
            }
        }

        self::assertArrayHasKey('int', $seenFormats);
    }

    public function testKeysAreUniqueWithinEachPreset(): void
    {
        $presetValues = array_column(TimeGridMetricPresets::all(), 'value');
        $presetValues[] = 'some-nonsense';

        foreach ($presetValues as $value) {
            $rows = TimeGridMetricPresets::rowsFor($value);
            $keys = array_column($rows, 'key');

            self::assertCount(
                count(array_unique($keys)),
                $keys,
                sprintf('Keys should be unique within preset "%s".', $value)
            );
        }
    }
}
