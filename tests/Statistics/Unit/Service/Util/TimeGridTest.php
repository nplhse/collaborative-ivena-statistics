<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Service\Util;

use App\Statistics\Infrastructure\Util\Period;
use App\Statistics\Infrastructure\Util\TimeGrid;
use PHPUnit\Framework\TestCase;

final class TimeGridTest extends TestCase
{
    public function testColumnsForYearReturns12MonthsPlusTotal(): void
    {
        $anchor = new \DateTimeImmutable('2025-03-15');

        $cols = TimeGrid::columns(Period::YEAR, $anchor);

        self::assertCount(13, $cols);

        $expectedLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        foreach (range(0, 11) as $i) {
            self::assertSame($expectedLabels[$i], $cols[$i]['label']);
            self::assertSame(sprintf('2025-%02d-01', $i + 1), $cols[$i]['periodKey']);
            self::assertArrayNotHasKey('isTotal', $cols[$i]);
        }

        $totalCol = $cols[12];
        self::assertSame('Total', $totalCol['label']);
        self::assertSame('TOTAL', $totalCol['periodKey']);
        self::assertTrue($totalCol['isTotal']);
    }

    public function testColumnsForUnknownGranularityFallsBackToYear(): void
    {
        $anchor = new \DateTimeImmutable('2030-07-10');

        $cols = TimeGrid::columns('something-unknown', $anchor);

        self::assertCount(13, $cols);
        self::assertSame('Jan', $cols[0]['label']);
        self::assertSame('2030-01-01', $cols[0]['periodKey']);
        self::assertSame('Total', $cols[12]['label']);
        self::assertSame('TOTAL', $cols[12]['periodKey']);
    }

    public function testColumnsForQuarterUsesQuarterOfAnchorMonth(): void
    {
        $anchorQ1 = new \DateTimeImmutable('2025-03-15');
        $colsQ1 = TimeGrid::columns(Period::QUARTER, $anchorQ1);
        self::assertCount(4, $colsQ1); // 3 Monate + Total
        self::assertSame('Jan', $colsQ1[0]['label']);
        self::assertSame('2025-01-01', $colsQ1[0]['periodKey']);
        self::assertSame('Mar', $colsQ1[2]['label']);
        self::assertSame('2025-03-01', $colsQ1[2]['periodKey']);
        self::assertSame('Total', $colsQ1[3]['label']);
        self::assertSame('TOTAL', $colsQ1[3]['periodKey']);
        self::assertTrue($colsQ1[3]['isTotal']);

        $anchorQ3 = new \DateTimeImmutable('2025-09-10');
        $colsQ3 = TimeGrid::columns(Period::QUARTER, $anchorQ3);
        self::assertCount(4, $colsQ3);
        self::assertSame('Jul', $colsQ3[0]['label']);
        self::assertSame('2025-07-01', $colsQ3[0]['periodKey']);
        self::assertSame('Sep', $colsQ3[2]['label']);
        self::assertSame('2025-09-01', $colsQ3[2]['periodKey']);
        self::assertSame('Total', $colsQ3[3]['label']);
        self::assertSame('TOTAL', $colsQ3[3]['periodKey']);
        self::assertTrue($colsQ3[3]['isTotal']);
    }

    public function testColumnsForMonthReturnsAllDaysOfMonthWithFormattedLabels(): void
    {
        $anchor = new \DateTimeImmutable('2024-02-15');

        $cols = TimeGrid::columns(Period::MONTH, $anchor);

        self::assertCount(29, $cols);

        self::assertSame('01.02.', $cols[0]['label']);
        self::assertSame('2024-02-01', $cols[0]['periodKey']);

        $last = $cols[28];
        self::assertSame('29.02.', $last['label']);
        self::assertSame('2024-02-29', $last['periodKey']);
    }

    public function testColumnsForWeekReturnsMondayToSunday(): void
    {
        $anchor = new \DateTimeImmutable('2025-11-05');

        $cols = TimeGrid::columns(Period::WEEK, $anchor);

        self::assertCount(7, $cols);

        $firstDate = new \DateTimeImmutable($cols[0]['periodKey']);
        self::assertSame('1', $firstDate->format('N'), 'First day should be Monday (N=1).');

        for ($i = 0; $i < 7; ++$i) {
            $d = $firstDate->modify(sprintf('+%d days', $i));
            self::assertSame($d->format('Y-m-d'), $cols[$i]['periodKey']);
            self::assertSame($d->format('D d.m.'), $cols[$i]['label']);
        }
    }

    public function testColumnsForDayReturnsSingleEntry(): void
    {
        $anchor = new \DateTimeImmutable('2025-11-08');

        $cols = TimeGrid::columns(Period::DAY, $anchor);

        self::assertCount(1, $cols);
        self::assertSame('2025-11-08', $cols[0]['label']);
        self::assertSame('2025-11-08', $cols[0]['periodKey']);
        self::assertArrayNotHasKey('isTotal', $cols[0]);
    }

    public function testColumnsForAllReturnsSingleAllColumn(): void
    {
        $anchor = new \DateTimeImmutable('2030-01-01');

        $cols = TimeGrid::columns(Period::ALL, $anchor);

        self::assertCount(1, $cols);
        self::assertSame('All', $cols[0]['label']);
        self::assertSame(Period::ALL_ANCHOR_DATE, $cols[0]['periodKey']);
        self::assertArrayNotHasKey('isTotal', $cols[0]);
    }
}
