<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application\TimeSeries;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\TimeSeries\TimeSeriesAxisFiller;
use App\Statistics\Application\TimeSeries\TimeSeriesGrain;
use PHPUnit\Framework\TestCase;

final class TimeSeriesAxisFillerTest extends TestCase
{
    public function testFillsTwelveMonthsForAPastYear(): void
    {
        $bounds = new StatisticsPeriodBounds(
            new \DateTimeImmutable('2024-01-01 00:00:00'),
            new \DateTimeImmutable('2025-01-01 00:00:00'),
        );
        $now = new \DateTimeImmutable('2026-09-03 12:00:00');
        $filled = TimeSeriesAxisFiller::fill(
            TimeSeriesGrain::Month,
            $bounds,
            ['2024-03' => 5, '2024-06' => 2],
            $now,
        );

        self::assertCount(12, $filled);
        self::assertSame('2024-01', $filled[0]['key']);
        self::assertSame(0, $filled[0]['count']);
        self::assertSame('2024-03', $filled[2]['key']);
        self::assertSame(5, $filled[2]['count']);
        self::assertSame('2024-12', $filled[11]['key']);
        self::assertSame(0, $filled[11]['count']);
    }

    public function testFillsQuarterMonthsIncludingGaps(): void
    {
        $bounds = new StatisticsPeriodBounds(
            new \DateTimeImmutable('2026-04-01 00:00:00'),
            new \DateTimeImmutable('2026-07-01 00:00:00'),
        );
        $now = new \DateTimeImmutable('2026-09-03 12:00:00');
        $filled = TimeSeriesAxisFiller::fill(
            TimeSeriesGrain::Month,
            $bounds,
            ['2026-06' => 17],
            $now,
        );

        self::assertSame(
            [
                ['key' => '2026-04', 'count' => 0],
                ['key' => '2026-05', 'count' => 0],
                ['key' => '2026-06', 'count' => 17],
            ],
            $filled,
        );
    }

    public function testClipsCurrentYearBeforeTheInProgressMonth(): void
    {
        $bounds = new StatisticsPeriodBounds(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2027-01-01 00:00:00'),
        );
        $now = new \DateTimeImmutable('2026-09-03 12:00:00');
        $filled = TimeSeriesAxisFiller::fill(
            TimeSeriesGrain::Month,
            $bounds,
            ['2026-01' => 1, '2026-09' => 4],
            $now,
        );

        $keys = array_column($filled, 'key');
        self::assertSame('2026-01', $keys[0]);
        self::assertSame('2026-08', $keys[array_key_last($keys)]);
        self::assertNotContains('2026-09', $keys);
        self::assertNotContains('2026-10', $keys);
        self::assertCount(8, $filled);
        self::assertSame(0, $filled[1]['count']);
    }

    public function testExcludesInProgressMonthFromCurrentQuarter(): void
    {
        $bounds = new StatisticsPeriodBounds(
            new \DateTimeImmutable('2026-07-01 00:00:00'),
            new \DateTimeImmutable('2026-10-01 00:00:00'),
        );
        $filled = TimeSeriesAxisFiller::fill(
            TimeSeriesGrain::Month,
            $bounds,
            ['2026-07' => 10, '2026-08' => 8, '2026-09' => 3],
            new \DateTimeImmutable('2026-09-03 12:00:00'),
        );

        self::assertSame(
            [
                ['key' => '2026-07', 'count' => 10],
                ['key' => '2026-08', 'count' => 8],
            ],
            $filled,
        );
    }

    public function testCurrentYearWithOnlyInProgressMonthIsEmpty(): void
    {
        $bounds = new StatisticsPeriodBounds(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2027-01-01 00:00:00'),
        );

        self::assertSame([], TimeSeriesAxisFiller::fill(
            TimeSeriesGrain::Month,
            $bounds,
            ['2026-09' => 4],
            new \DateTimeImmutable('2026-09-03 12:00:00'),
        ));
    }

    public function testFillsDaysInAThirtyDayMonth(): void
    {
        $bounds = new StatisticsPeriodBounds(
            new \DateTimeImmutable('2026-04-01 00:00:00'),
            new \DateTimeImmutable('2026-05-01 00:00:00'),
        );
        $now = new \DateTimeImmutable('2026-09-03 12:00:00');
        $filled = TimeSeriesAxisFiller::fill(
            TimeSeriesGrain::Day,
            $bounds,
            ['2026-04-01' => 12, '2026-04-03' => 17],
            $now,
        );

        self::assertCount(30, $filled);
        self::assertSame('2026-04-01', $filled[0]['key']);
        self::assertSame(12, $filled[0]['count']);
        self::assertSame('2026-04-02', $filled[1]['key']);
        self::assertSame(0, $filled[1]['count']);
        self::assertSame('2026-04-03', $filled[2]['key']);
        self::assertSame(17, $filled[2]['count']);
        self::assertSame('2026-04-30', $filled[29]['key']);
    }

    public function testFillsFebruaryInACommonYear(): void
    {
        $bounds = new StatisticsPeriodBounds(
            new \DateTimeImmutable('2025-02-01 00:00:00'),
            new \DateTimeImmutable('2025-03-01 00:00:00'),
        );
        $filled = TimeSeriesAxisFiller::fill(
            TimeSeriesGrain::Day,
            $bounds,
            ['2025-02-01' => 1],
            new \DateTimeImmutable('2026-09-03 12:00:00'),
        );

        self::assertCount(28, $filled);
        self::assertSame('2025-02-28', $filled[27]['key']);
    }

    public function testFillsFebruaryInALeapYear(): void
    {
        $bounds = new StatisticsPeriodBounds(
            new \DateTimeImmutable('2024-02-01 00:00:00'),
            new \DateTimeImmutable('2024-03-01 00:00:00'),
        );
        $filled = TimeSeriesAxisFiller::fill(
            TimeSeriesGrain::Day,
            $bounds,
            ['2024-02-29' => 3],
            new \DateTimeImmutable('2026-09-03 12:00:00'),
        );

        self::assertCount(29, $filled);
        self::assertSame('2024-02-29', $filled[28]['key']);
        self::assertSame(3, $filled[28]['count']);
    }

    public function testClipsCurrentMonthToToday(): void
    {
        $bounds = new StatisticsPeriodBounds(
            new \DateTimeImmutable('2026-09-01 00:00:00'),
            new \DateTimeImmutable('2026-10-01 00:00:00'),
        );
        $filled = TimeSeriesAxisFiller::fill(
            TimeSeriesGrain::Day,
            $bounds,
            ['2026-09-01' => 12],
            new \DateTimeImmutable('2026-09-03 15:00:00'),
        );

        $keys = array_column($filled, 'key');
        self::assertSame(['2026-09-01', '2026-09-02', '2026-09-03'], $keys);
        self::assertSame(0, $filled[1]['count']);
        self::assertNotContains('2026-09-04', $keys);
    }

    public function testOpenPeriodStopsBeforeTheInProgressMonth(): void
    {
        $bounds = new StatisticsPeriodBounds(new \DateTimeImmutable('2025-10-01 00:00:00'));
        $filled = TimeSeriesAxisFiller::fill(
            TimeSeriesGrain::Month,
            $bounds,
            ['2026-01' => 2, '2026-03' => 5],
            new \DateTimeImmutable('2026-03-15 12:00:00'),
        );

        $keys = array_column($filled, 'key');
        self::assertSame('2026-01', $keys[0]);
        self::assertSame('2026-02', $keys[array_key_last($keys)]);
        self::assertSame(0, $filled[1]['count']);
        self::assertNotContains('2026-03', $keys);
        self::assertNotContains('2025-12', $keys);
    }

    public function testEmptyCountsStayEmpty(): void
    {
        $bounds = new StatisticsPeriodBounds(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2027-01-01 00:00:00'),
        );

        self::assertSame([], TimeSeriesAxisFiller::fill(
            TimeSeriesGrain::Month,
            $bounds,
            [],
            new \DateTimeImmutable('2026-09-03 12:00:00'),
        ));
    }

    public function testCalendarMonthKeysForClosedQuarter(): void
    {
        $keys = TimeSeriesAxisFiller::calendarMonthKeys(
            new StatisticsPeriodBounds(
                new \DateTimeImmutable('2026-04-01 00:00:00'),
                new \DateTimeImmutable('2026-07-01 00:00:00'),
            ),
            new \DateTimeImmutable('2026-09-03 12:00:00'),
        );

        self::assertSame(['4', '5', '6'], $keys);
    }

    public function testCalendarMonthKeysExcludeInProgressMonth(): void
    {
        $keys = TimeSeriesAxisFiller::calendarMonthKeys(
            new StatisticsPeriodBounds(
                new \DateTimeImmutable('2026-07-01 00:00:00'),
                new \DateTimeImmutable('2026-10-01 00:00:00'),
            ),
            new \DateTimeImmutable('2026-09-03 12:00:00'),
        );

        self::assertSame(['7', '8'], $keys);
    }

    public function testIncludesLastCompleteMonthOnTheFirstOfTheNext(): void
    {
        $filled = TimeSeriesAxisFiller::fill(
            TimeSeriesGrain::Month,
            new StatisticsPeriodBounds(
                new \DateTimeImmutable('2026-01-01 00:00:00'),
                new \DateTimeImmutable('2027-01-01 00:00:00'),
            ),
            ['2026-08' => 9, '2026-09' => 1],
            new \DateTimeImmutable('2026-09-01 00:00:00'),
        );

        $keys = array_column($filled, 'key');
        self::assertContains('2026-08', $keys);
        self::assertNotContains('2026-09', $keys);
        self::assertSame(9, $filled[array_key_last($keys)]['count']);
    }

    public function testCalendarMonthKeysSkipOpenPeriods(): void
    {
        self::assertSame([], TimeSeriesAxisFiller::calendarMonthKeys(
            new StatisticsPeriodBounds(new \DateTimeImmutable('2025-10-01 00:00:00')),
            new \DateTimeImmutable('2026-09-03 12:00:00'),
        ));
    }
}
