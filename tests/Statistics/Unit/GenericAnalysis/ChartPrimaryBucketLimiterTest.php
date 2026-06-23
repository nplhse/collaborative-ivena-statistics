<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\GenericAnalysis\Application\ChartPrimaryBucketLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ChartPrimaryBucketLimiterTest extends TestCase
{
    private ChartPrimaryBucketLimiter $limiter;

    protected function setUp(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Other');

        $this->limiter = new ChartPrimaryBucketLimiter($translator);
    }

    public function testLimitsSingleSeriesToTopFivePlusOther(): void
    {
        $labels = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
        $values = [50, 40, 30, 20, 10, 5, 1];

        [$limitedLabels, $limitedValues, $series] = $this->limiter->limit($labels, $values, [], 5);

        self::assertCount(6, $limitedLabels);
        self::assertSame('Other', $limitedLabels[5]);
        self::assertEquals(6, $limitedValues[5]);
        self::assertSame([], $series);
    }

    public function testLimitsMultiSeriesRowBuckets(): void
    {
        $labels = ['A', 'B', 'C', 'D', 'E', 'F'];
        $series = [
            ['name' => 'Male', 'data' => [10, 8, 6, 4, 2, 1]],
            ['name' => 'Female', 'data' => [5, 4, 3, 2, 1, 1]],
        ];

        [$limitedLabels, $limitedValues, $limitedSeries] = $this->limiter->limit($labels, [], $series, 3);

        self::assertCount(4, $limitedLabels);
        self::assertSame('Other', $limitedLabels[3]);
        self::assertCount(2, $limitedSeries);
        self::assertCount(4, $limitedSeries[0]['data']);
        self::assertEquals(7, $limitedSeries[0]['data'][3]);
        self::assertSame([], $limitedValues);
    }

    public function testLimitsSingleSeriesToTopFiveWithoutRemainderBucketWhenDisabled(): void
    {
        $labels = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
        $values = [50, 40, 30, 20, 10, 5, 1];

        [$limitedLabels, $limitedValues, $series] = $this->limiter->limit($labels, $values, [], 5, includeRemainderBucket: false);

        self::assertCount(5, $limitedLabels);
        self::assertSame(['A', 'B', 'C', 'D', 'E'], $limitedLabels);
        self::assertSame([50, 40, 30, 20, 10], $limitedValues);
        self::assertSame([], $series);
    }

    public function testLeavesDataUnchangedWhenWithinCap(): void
    {
        $labels = ['A', 'B', 'C'];
        $values = [1, 2, 3];

        [$limitedLabels, $limitedValues, $limitedSeries] = $this->limiter->limit($labels, $values, [], 5);

        self::assertSame($labels, $limitedLabels);
        self::assertSame($values, $limitedValues);
        self::assertSame([], $limitedSeries);
    }
}
