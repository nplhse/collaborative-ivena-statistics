<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Unit\Application;

use App\Engagement\Application\MonthlyReminderPeriodResolver;
use PHPUnit\Framework\TestCase;

final class MonthlyReminderPeriodResolverTest extends TestCase
{
    public function testResolveUsesPreviousMonthAsReportingPeriod(): void
    {
        $resolver = new MonthlyReminderPeriodResolver();
        $result = $resolver->resolve(new \DateTimeImmutable('2026-01-15 10:00:00', new \DateTimeZone('Europe/Berlin')));

        self::assertSame(2025, $result['reportingYear']);
        self::assertSame(12, $result['reportingMonth']);
        self::assertSame(2026, $result['uploadYear']);
        self::assertSame(1, $result['uploadMonth']);
        self::assertCount(12, $result['chartMonthKeys']);
        self::assertSame('2026-01', $result['chartMonthKeys'][11]);
        self::assertSame('2025-02', $result['chartMonthKeys'][0]);
    }
}
