<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\ClosureAnalytics\Application\Volume\ClosureCoverage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClosureCoverage::class)]
final class ClosureCoverageTest extends TestCase
{
    #[Test]
    public function mergesTouchingRangesAndReportsGaps(): void
    {
        $coverage = ClosureCoverage::fromRanges([
            [new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-03-01')],
            [new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-02-01')],
            [new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-05-01')],
        ]);

        self::assertCount(2, $coverage->ranges());
        self::assertSame('2026-03-01', $coverage->gaps()[0][0]->format('Y-m-d'));
        self::assertSame('2026-04-01', $coverage->gaps()[0][1]->format('Y-m-d'));
        self::assertTrue($coverage->covers(new \DateTimeImmutable('2026-01-15'), new \DateTimeImmutable('2026-02-20')));
        self::assertFalse($coverage->covers(new \DateTimeImmutable('2026-02-15'), new \DateTimeImmutable('2026-04-02')));
        self::assertSame('2026-03-01', $coverage->coveredUntil(new \DateTimeImmutable('2026-01-10 12:00'))?->format('Y-m-d'));
        self::assertNull($coverage->coveredUntil(new \DateTimeImmutable('2026-03-10')));
    }

    #[Test]
    public function coveredDaysContainOnlyFullyCoveredDaysInsideTheWindow(): void
    {
        $coverage = ClosureCoverage::fromRanges([
            [new \DateTimeImmutable('2026-03-02 12:00'), new \DateTimeImmutable('2026-03-05 00:00')],
        ]);

        $days = $coverage->coveredDays(new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-10'));

        self::assertSame(['2026-03-03', '2026-03-04'], array_keys($days));
        self::assertSame(
            ['2026-03-04'],
            array_keys($coverage->coveredDays(new \DateTimeImmutable('2026-03-04'), new \DateTimeImmutable('2026-03-10'))),
        );
    }

    #[Test]
    public function daylightSavingDaysCountAsCalendarDays(): void
    {
        $coverage = ClosureCoverage::fromRanges([
            [new \DateTimeImmutable('2026-03-28'), new \DateTimeImmutable('2026-03-31')],
            [new \DateTimeImmutable('2026-10-24'), new \DateTimeImmutable('2026-10-27')],
        ]);

        self::assertArrayHasKey('2026-03-29', $coverage->coveredDays(new \DateTimeImmutable('2026-03-28'), new \DateTimeImmutable('2026-03-31')));
        self::assertArrayHasKey('2026-10-25', $coverage->coveredDays(new \DateTimeImmutable('2026-10-24'), new \DateTimeImmutable('2026-10-27')));
    }

    #[Test]
    public function emptyCoverageCoversNothing(): void
    {
        $coverage = ClosureCoverage::fromRanges([]);

        self::assertTrue($coverage->isEmpty());
        self::assertSame([], $coverage->coveredDays(new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-10')));
    }
}
