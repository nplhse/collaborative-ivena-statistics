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
}
