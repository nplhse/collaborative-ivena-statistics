<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use PHPUnit\Framework\TestCase;

final class StatisticsPeriodBoundsTest extends TestCase
{
    public function testIntersectTakesTheLaterFromAndEarlierTo(): void
    {
        $bounds = new StatisticsPeriodBounds(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-04-01 00:00:00'),
        );

        $intersected = $bounds->intersect(
            new \DateTimeImmutable('2026-02-15 00:00:00'),
            new \DateTimeImmutable('2026-03-16 00:00:00'),
        );

        self::assertSame('2026-02-15 00:00:00', $intersected->from?->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-16 00:00:00', $intersected->toExclusive?->format('Y-m-d H:i:s'));
    }

    public function testIntersectKeepsOpenBoundsWhenTheOtherSideIsMissing(): void
    {
        $open = new StatisticsPeriodBounds(null);

        $fromOnly = $open->intersect(new \DateTimeImmutable('2026-03-01 00:00:00'), null);
        self::assertSame('2026-03-01 00:00:00', $fromOnly->from?->format('Y-m-d H:i:s'));
        self::assertNull($fromOnly->toExclusive);

        $toOnly = $open->intersect(null, new \DateTimeImmutable('2026-03-16 00:00:00'));
        self::assertNull($toOnly->from);
        self::assertSame('2026-03-16 00:00:00', $toOnly->toExclusive?->format('Y-m-d H:i:s'));
    }
}
