<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Unit\Application;

use App\Engagement\Application\FirstWorkingDayResolver;
use App\Engagement\Application\MonthlyReminderDispatchGuard;
use PHPUnit\Framework\TestCase;

final class MonthlyReminderDispatchGuardTest extends TestCase
{
    private MonthlyReminderDispatchGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new MonthlyReminderDispatchGuard(new FirstWorkingDayResolver());
    }

    public function testReturnsTrueOnFirstWorkingDayOfJune2026(): void
    {
        self::assertTrue($this->guard->shouldDispatchToday(
            new \DateTimeImmutable('2026-06-01 08:00:00', new \DateTimeZone('Europe/Berlin')),
        ));
    }

    public function testReturnsFalseOnRegularWeekday(): void
    {
        self::assertFalse($this->guard->shouldDispatchToday(
            new \DateTimeImmutable('2026-06-02 08:00:00', new \DateTimeZone('Europe/Berlin')),
        ));
    }

    public function testReturnsFalseOnMondayThatIsNotFirstWorkingDay(): void
    {
        self::assertFalse($this->guard->shouldDispatchToday(
            new \DateTimeImmutable('2026-06-08 08:00:00', new \DateTimeZone('Europe/Berlin')),
        ));
    }

    public function testReturnsTrueOnFirstWorkingDayAfterLabourDay2026(): void
    {
        self::assertTrue($this->guard->shouldDispatchToday(
            new \DateTimeImmutable('2026-05-04 08:00:00', new \DateTimeZone('Europe/Berlin')),
        ));
    }

    public function testReturnsFalseOnLabourDay(): void
    {
        self::assertFalse($this->guard->shouldDispatchToday(
            new \DateTimeImmutable('2026-05-01 08:00:00', new \DateTimeZone('Europe/Berlin')),
        ));
    }

    public function testReturnsTrueOnFirstWorkingDayOfJuly2026(): void
    {
        self::assertTrue($this->guard->shouldDispatchToday(
            new \DateTimeImmutable('2026-07-01 08:00:00', new \DateTimeZone('Europe/Berlin')),
        ));
    }
}
