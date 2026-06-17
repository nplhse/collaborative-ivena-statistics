<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Unit\Application;

use App\Engagement\Application\MonthlyReminderChartBuilder;
use App\Statistics\Application\ChartBucketMapper;
use PHPUnit\Framework\TestCase;

final class MonthlyReminderChartBuilderTest extends TestCase
{
    public function testBuildCreatesBarsForEachMonth(): void
    {
        $builder = new MonthlyReminderChartBuilder(new ChartBucketMapper());

        $bars = $builder->build(
            ['2025-11', '2025-12'],
            ['Nov', 'Dec'],
            [
                ['year' => 2025, 'month' => 11, 'count' => 10],
                ['year' => 2025, 'month' => 12, 'count' => 20],
            ],
            '2025-12',
        );

        self::assertCount(2, $bars);
        self::assertSame(10, $bars[0]->allocationCount);
        self::assertSame(20, $bars[1]->allocationCount);
        self::assertTrue($bars[1]->isReportingMonth);
        self::assertSame(100.0, $builder->percentChange(20, 10));
    }

    public function testPercentChangeReturnsNullWhenBothMonthsAreZero(): void
    {
        $builder = new MonthlyReminderChartBuilder(new ChartBucketMapper());

        self::assertNull($builder->percentChange(0, 0));
    }

    public function testSummarizeTrendDetectsGrowingSeries(): void
    {
        $builder = new MonthlyReminderChartBuilder(new ChartBucketMapper());

        self::assertSame(
            'monthly_reminder.trend.growing',
            $builder->summarizeTrend([5, 8, 12, 16, 20, 30]),
        );
    }

    public function testSummarizeTrendDetectsDecliningSeries(): void
    {
        $builder = new MonthlyReminderChartBuilder(new ChartBucketMapper());

        self::assertSame(
            'monthly_reminder.trend.declining',
            $builder->summarizeTrend([30, 25, 20, 15, 10, 5]),
        );
    }

    public function testSummarizeTrendDetectsGrowthFromZero(): void
    {
        $builder = new MonthlyReminderChartBuilder(new ChartBucketMapper());

        self::assertSame(
            'monthly_reminder.trend.growing_from_zero',
            $builder->summarizeTrend([0, 0, 0, 5]),
        );
    }

    public function testSummarizeTrendReturnsStableForFlatSeries(): void
    {
        $builder = new MonthlyReminderChartBuilder(new ChartBucketMapper());

        self::assertSame(
            'monthly_reminder.trend.stable',
            $builder->summarizeTrend([10, 10, 10, 10]),
        );
    }
}
