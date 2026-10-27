<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Unit\Application;

use App\Engagement\Application\MonthlyReminderPeriodResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class MonthlyReminderPeriodResolverTest extends TestCase
{
    public function testResolveUsesLastCompleteMonthForInsightsAndPreviousMonthForUpload(): void
    {
        $resolver = new MonthlyReminderPeriodResolver(new MockClock());
        $result = $resolver->resolve(new \DateTimeImmutable('2026-01-15 10:00:00', new \DateTimeZone('Europe/Berlin')));

        self::assertSame(2025, $result['reportingYear']);
        self::assertSame(11, $result['reportingMonth']);
        self::assertSame(2025, $result['uploadYear']);
        self::assertSame(12, $result['uploadMonth']);
        self::assertCount(12, $result['chartMonthKeys']);
        self::assertSame('2026-01', $result['chartMonthKeys'][11]);
        self::assertSame('2025-02', $result['chartMonthKeys'][0]);
    }

    public function testResolveOnFirstOfMonthSeparatesUploadAndInsightsByOneMonth(): void
    {
        $resolver = new MonthlyReminderPeriodResolver(new MockClock());
        $result = $resolver->resolve(new \DateTimeImmutable('2026-07-01 08:00:00', new \DateTimeZone('Europe/Berlin')));

        self::assertSame(2026, $result['reportingYear']);
        self::assertSame(5, $result['reportingMonth']);
        self::assertSame(2026, $result['uploadYear']);
        self::assertSame(6, $result['uploadMonth']);
    }

    public function testResolveWithoutReferenceDateUsesTheBerlinDateOfTheClock(): void
    {
        // 23:30 UTC on 30 June is already 1 July in Berlin (CEST, UTC+2).
        $resolver = new MonthlyReminderPeriodResolver(new MockClock('2026-06-30 23:30:00', 'UTC'));
        $result = $resolver->resolve();

        self::assertSame(2026, $result['reportingYear']);
        self::assertSame(5, $result['reportingMonth']);
        self::assertSame(6, $result['uploadMonth']);
    }
}
