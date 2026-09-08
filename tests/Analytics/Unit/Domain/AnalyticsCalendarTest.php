<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Unit\Domain;

use App\Analytics\Domain\AnalyticsCalendar;
use PHPUnit\Framework\TestCase;

final class AnalyticsCalendarTest extends TestCase
{
    public function testDayBoundsUseEuropeBerlinMidnightToMidnight(): void
    {
        $day = new \DateTimeImmutable('2026-03-29 15:45:00', new \DateTimeZone('UTC'));
        [$from, $to] = AnalyticsCalendar::dayBounds($day);

        self::assertSame('Europe/Berlin', $from->getTimezone()->getName());
        self::assertSame('2026-03-29 00:00:00', $from->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-30 00:00:00', $to->format('Y-m-d H:i:s'));
        self::assertSame('Europe/Berlin', AnalyticsCalendar::timezone()->getName());
    }

    public function testStartOfDayAndRelativeDaysStayOnBerlinCalendar(): void
    {
        $today = AnalyticsCalendar::startOfToday();
        $yesterday = AnalyticsCalendar::yesterday();
        $twoDaysAgo = AnalyticsCalendar::daysAgo(2);

        self::assertSame('00:00:00', $today->format('H:i:s'));
        self::assertSame($today->modify('-1 day')->format('Y-m-d'), $yesterday->format('Y-m-d'));
        self::assertSame($today->modify('-2 days')->format('Y-m-d'), $twoDaysAgo->format('Y-m-d'));
        self::assertSame(
            $today->format('Y-m-d'),
            AnalyticsCalendar::startOfDay($today->setTime(23, 59))->format('Y-m-d'),
        );
    }
}
