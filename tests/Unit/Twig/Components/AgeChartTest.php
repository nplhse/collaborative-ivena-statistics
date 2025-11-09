<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig\Components;

use App\Twig\Components\AgeChart;
use PHPUnit\Framework\TestCase;

final class AgeChartTest extends TestCase
{
    public function testMountSetsAllPropsAndGeneratesDomId(): void
    {
        $component = new AgeChart();

        $labels = ['0–9', '10–19', '20–29'];
        $series = [
            ['name' => 'male', 'data' => [0, 0, 0]],
            ['name' => 'female', 'data' => [0, 0, 0]],
        ];

        $component->mount($labels, $series);

        self::assertSame($labels, $component->labels);
        self::assertSame($series, $component->series);
        self::assertSame(240, $component->height);
        self::assertNotSame('', $component->domId);
        self::assertStringStartsWith('chart-age-', $component->domId);
        self::assertNull($component->mean);
    }

    public function testMountAllowsCustomHeightAndDomIdAndMean(): void
    {
        $component = new AgeChart();

        $labels = ['0–9', '10–19'];
        $series = [['name' => 'all', 'data' => [1, 2]]];

        $component->mount($labels, $series, height: 320, domId: 'chart-age-fixed', mean: 37.5);

        self::assertSame(320, $component->height);
        self::assertSame('chart-age-fixed', $component->domId);
        self::assertSame(37.5, $component->mean);
    }

    public function testHasDataReturnsFalseWhenSeriesEmpty(): void
    {
        $component = new AgeChart();

        $component->mount(labels: [], series: []);

        self::assertFalse($component->hasData());
    }

    public function testHasDataReturnsFalseWhenAllValuesZeroOrEmpty(): void
    {
        $component = new AgeChart();

        $component->mount(
            labels: ['0–9', '10–19'],
            series: [
                ['name' => 'male', 'data' => [0, 0]],
                ['name' => 'female', 'data' => []],
            ]
        );

        self::assertFalse($component->hasData());
    }

    public function testHasDataReturnsTrueWhenAnySeriesHasPositiveSum(): void
    {
        $component = new AgeChart();

        $component->mount(
            labels: ['0–9', '10–19', '20–29'],
            series: [
                ['name' => 'male', 'data' => [0, 0, 0]],
                ['name' => 'female', 'data' => [0, 1, 0]],
            ]
        );

        self::assertTrue($component->hasData());
    }

    public function testHasDataCastsValuesToFloatSoNumericStringsAlsoCount(): void
    {
        $component = new AgeChart();

        $component->mount(
            labels: ['0–9', '10–19'],
            series: [
                ['name' => 'all', 'data' => [0, 2.5]],
            ]
        );

        self::assertTrue($component->hasData());
    }
}
