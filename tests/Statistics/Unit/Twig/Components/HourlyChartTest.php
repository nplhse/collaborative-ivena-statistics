<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Twig\Components;

use App\Statistics\UI\Twig\Components\HourlyChart;
use PHPUnit\Framework\TestCase;

final class HourlyChartTest extends TestCase
{
    public function testMountSetsProperties(): void
    {
        $cmp = new HourlyChart();

        $labels = ['00:00', '01:00'];
        $series = [
            ['name' => 'Total', 'data' => [1, 2]],
        ];

        $cmp->mount($labels, $series, height: 300, domId: 'abc123');

        self::assertSame($labels, $cmp->labels);
        self::assertSame($series, $cmp->series);
        self::assertSame(300, $cmp->height);
        self::assertSame('abc123', $cmp->domId);
    }

    public function testMountGeneratesDomIdWhenNoneProvided(): void
    {
        $cmp = new HourlyChart();

        $cmp->mount(['00:00'], [['name' => 'x', 'data' => [1]]]);

        self::assertNotEmpty($cmp->domId);
        self::assertStringStartsWith('chart-hourly-', $cmp->domId);
        self::assertGreaterThan(15, strlen($cmp->domId));
    }

    public function testHasDataReturnsFalseWhenSeriesEmpty(): void
    {
        $cmp = new HourlyChart();
        $cmp->mount(['00:00'], []);

        self::assertFalse($cmp->hasData());
    }

    public function testHasDataReturnsFalseWhenAllSeriesAreZero(): void
    {
        $cmp = new HourlyChart();

        $cmp->mount(
            ['00:00', '01:00'],
            [
                ['name' => 'A', 'data' => [0, 0]],
                ['name' => 'B', 'data' => []],
                ['name' => 'C', 'data' => [0]],
            ]
        );

        self::assertFalse($cmp->hasData());
    }

    public function testHasDataReturnsTrueWhenAnySeriesHasPositiveSum(): void
    {
        $cmp = new HourlyChart();

        $cmp->mount(
            ['00:00', '01:00'],
            [
                ['name' => 'A', 'data' => [0, 0]],
                ['name' => 'B', 'data' => [0, 3]],
            ]
        );

        self::assertTrue($cmp->hasData());
    }
}
