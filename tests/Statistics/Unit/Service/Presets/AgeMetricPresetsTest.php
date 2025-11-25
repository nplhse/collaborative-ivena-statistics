<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Service\Presets;

use App\Statistics\Infrastructure\Presets\AgeMetricPresets;
use PHPUnit\Framework\TestCase;

final class AgeMetricPresetsTest extends TestCase
{
    public function testPresetTotalIsDefault(): void
    {
        $metrics = AgeMetricPresets::metricsFor('total');

        self::assertCount(1, $metrics);
        self::assertSame('Total', $metrics[0]['name']);
        self::assertSame('total', $metrics[0]['col']);
    }

    public function testUnknownPresetFallsBackToTotal(): void
    {
        $metrics = AgeMetricPresets::metricsFor('something-strange');

        self::assertCount(1, $metrics);
        self::assertSame('Total', $metrics[0]['name']);
        self::assertSame('total', $metrics[0]['col']);
    }

    public function testPresetGender(): void
    {
        $metrics = AgeMetricPresets::metricsFor('gender');

        self::assertSame([
            ['name' => 'Male',   'col' => 'gender_m'],
            ['name' => 'Female', 'col' => 'gender_w'],
            ['name' => 'Diverse', 'col' => 'gender_d'],
        ], $metrics);
    }

    public function testPresetUrgency(): void
    {
        $metrics = AgeMetricPresets::metricsFor('urgency');

        self::assertSame([
            ['name' => 'Urgency 1', 'col' => 'urg_1'],
            ['name' => 'Urgency 2', 'col' => 'urg_2'],
            ['name' => 'Urgency 3', 'col' => 'urg_3'],
        ], $metrics);
    }

    public function testPresetClinical(): void
    {
        $metrics = AgeMetricPresets::metricsFor('clinical');

        self::assertSame([
            ['name' => 'Ventilated',     'col' => 'is_ventilated'],
            ['name' => 'CPR',            'col' => 'is_cpr'],
            ['name' => 'Shock',          'col' => 'is_shock'],
            ['name' => 'Pregnant',       'col' => 'is_pregnant'],
            ['name' => 'With Physician', 'col' => 'with_physician'],
            ['name' => 'Infectious',     'col' => 'infectious'],
        ], $metrics);
    }

    public function testPresetResources(): void
    {
        $metrics = AgeMetricPresets::metricsFor('resources');

        self::assertSame([
            ['name' => 'Cathlab required', 'col' => 'cathlab_required'],
            ['name' => 'Resus required',   'col' => 'resus_required'],
        ], $metrics);
    }

    public function testAllReturnsExpectedPresetList(): void
    {
        $all = AgeMetricPresets::all();

        self::assertSame([
            ['value' => 'total',     'label' => 'Total'],
            ['value' => 'gender',    'label' => 'Gender'],
            ['value' => 'urgency',   'label' => 'Urgency'],
            ['value' => 'clinical',  'label' => 'Clinical'],
            ['value' => 'resources', 'label' => 'Resources'],
        ], $all);
    }
}
