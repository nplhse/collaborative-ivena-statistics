<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\SummarizedReport;

use App\Statistics\Application\SummarizedReport\Monthly\MonthlyReportPeriodResolver;
use PHPUnit\Framework\TestCase;

final class MonthlyReportPeriodResolverTest extends TestCase
{
    public function testResolvesPreviousCompletedCalendarMonth(): void
    {
        $resolver = new MonthlyReportPeriodResolver();
        $period = $resolver->resolve(new \DateTimeImmutable('2026-08-12 10:00:00', new \DateTimeZone('Europe/Berlin')));

        self::assertSame(2026, $period['year']);
        self::assertSame(7, $period['month']);
        self::assertSame('2026-07-01', $period['monthStart']->format('Y-m-d'));
        self::assertSame('2026-08-01', $period['monthEndExclusive']->format('Y-m-d'));
        self::assertSame('2026-06-01', $period['previousMonthStart']->format('Y-m-d'));
        self::assertSame('2026-07-01', $period['previousMonthEndExclusive']->format('Y-m-d'));
        self::assertSame('2025-07-01', $period['yearAgoMonthStart']->format('Y-m-d'));
        self::assertSame('2025-08-01', $period['yearAgoMonthEndExclusive']->format('Y-m-d'));
        self::assertFalse($period['navigationNextEnabled']);
        self::assertSame(2026, $period['navigationPreviousYear']);
        self::assertSame(6, $period['navigationPreviousMonth']);
    }

    public function testResolvesYearBoundary(): void
    {
        $resolver = new MonthlyReportPeriodResolver();
        $period = $resolver->resolve(new \DateTimeImmutable('2026-01-05 08:00:00', new \DateTimeZone('Europe/Berlin')));

        self::assertSame(2025, $period['year']);
        self::assertSame(12, $period['month']);
        self::assertSame('2025-12-01', $period['monthStart']->format('Y-m-d'));
        self::assertSame('2026-01-01', $period['monthEndExclusive']->format('Y-m-d'));
    }

    public function testResolvesExplicitCompletedMonthAndEnablesNext(): void
    {
        $resolver = new MonthlyReportPeriodResolver();
        $period = $resolver->resolve(
            new \DateTimeImmutable('2026-08-12 10:00:00', new \DateTimeZone('Europe/Berlin')),
            2026,
            5,
        );

        self::assertSame(2026, $period['year']);
        self::assertSame(5, $period['month']);
        self::assertTrue($period['navigationNextEnabled']);
        self::assertSame(2026, $period['navigationNextYear']);
        self::assertSame(6, $period['navigationNextMonth']);
        self::assertSame(2026, $period['navigationPreviousYear']);
        self::assertSame(4, $period['navigationPreviousMonth']);
    }

    public function testClampsFutureMonthToLatestCompleted(): void
    {
        $resolver = new MonthlyReportPeriodResolver();
        $period = $resolver->resolve(
            new \DateTimeImmutable('2026-08-12 10:00:00', new \DateTimeZone('Europe/Berlin')),
            2026,
            8,
        );

        self::assertSame(2026, $period['year']);
        self::assertSame(7, $period['month']);
        self::assertFalse($period['navigationNextEnabled']);
    }
}
