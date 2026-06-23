<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Unit\Application;

use App\Engagement\Application\FirstWorkingDayResolver;
use PHPUnit\Framework\TestCase;

final class FirstWorkingDayResolverTest extends TestCase
{
    private FirstWorkingDayResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new FirstWorkingDayResolver();
    }

    public function testReturnsWeekdayWhenMonthStartsOnMonday(): void
    {
        $result = $this->resolver->forMonth(new \DateTimeImmutable('2026-06-15', new \DateTimeZone('Europe/Berlin')));

        self::assertSame('2026-06-01', $result->format('Y-m-d'));
        self::assertSame('08:00', $result->format('H:i'));
    }

    public function testSkipsWeekendWhenMonthStartsOnSaturday(): void
    {
        $result = $this->resolver->forMonth(new \DateTimeImmutable('2026-08-15', new \DateTimeZone('Europe/Berlin')));

        self::assertSame('2026-08-03', $result->format('Y-m-d'));
    }

    public function testSkipsNewYearsDay(): void
    {
        $result = $this->resolver->forMonth(new \DateTimeImmutable('2026-01-15', new \DateTimeZone('Europe/Berlin')));

        self::assertSame('2026-01-02', $result->format('Y-m-d'));
    }

    public function testSkipsLabourDayWeekendInMay2026(): void
    {
        $result = $this->resolver->forMonth(new \DateTimeImmutable('2026-05-15', new \DateTimeZone('Europe/Berlin')));

        self::assertSame('2026-05-04', $result->format('Y-m-d'));
    }

    public function testFebruary2026StartsOnSunday(): void
    {
        $result = $this->resolver->forMonth(new \DateTimeImmutable('2026-02-10', new \DateTimeZone('Europe/Berlin')));

        self::assertSame('2026-02-02', $result->format('Y-m-d'));
    }
}
