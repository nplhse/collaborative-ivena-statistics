<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\ClosureAnalytics\Application\ClosureDayTimelineFactory;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureDayTimelineItem;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventType;
use PHPUnit\Framework\TestCase;

final class ClosureDayTimelineFactoryTest extends TestCase
{
    public function testPlacesASameDayClosureOnAFullBerlinCalendarDay(): void
    {
        $timezone = new \DateTimeZone('Europe/Berlin');
        $days = new ClosureDayTimelineFactory()->build([
            new ClosureDayTimelineItem(
                new \DateTimeImmutable('2026-05-01 10:00:00', $timezone),
                new \DateTimeImmutable('2026-05-01 12:00:00', $timezone),
                'Cardiology',
                'Inner Medicine',
                'emergency',
                '/interval/1',
                '10:00–12:00 · Cardiology',
            ),
        ]);

        self::assertCount(1, $days);
        self::assertSame('2026-05-01', $days[0]->dayKey);
        self::assertSame('00:00', $days[0]->ticks[0]['label']);
        self::assertSame('24:00', $days[0]->ticks[6]['label']);
        self::assertCount(1, $days[0]->lanes);
        self::assertEqualsWithDelta(10 / 24 * 100, $days[0]->lanes[0]->segments[0]->left, 0.01);
        self::assertEqualsWithDelta(2 / 24 * 100, $days[0]->lanes[0]->segments[0]->width, 0.01);
    }

    public function testSplitsAMidnightCrossingClosureIntoTwoCalendarDays(): void
    {
        $timezone = new \DateTimeZone('Europe/Berlin');
        $days = new ClosureDayTimelineFactory()->build([
            new ClosureDayTimelineItem(
                new \DateTimeImmutable('2026-05-01 23:00:00', $timezone),
                new \DateTimeImmutable('2026-05-02 01:00:00', $timezone),
                'Trauma',
                'Surgery',
                'inpatient',
                '/interval/2',
                '23:00–01:00 · Trauma',
            ),
        ]);

        self::assertCount(2, $days);
        self::assertSame('2026-05-01', $days[0]->dayKey);
        self::assertSame('2026-05-02', $days[1]->dayKey);
        self::assertEqualsWithDelta(23 / 24 * 100, $days[0]->lanes[0]->segments[0]->left, 0.01);
        self::assertEqualsWithDelta(1 / 24 * 100, $days[0]->lanes[0]->segments[0]->width, 0.01);
        self::assertEqualsWithDelta(0.0, $days[1]->lanes[0]->segments[0]->left, 0.01);
        self::assertEqualsWithDelta(1 / 24 * 100, $days[1]->lanes[0]->segments[0]->width, 0.01);
    }

    public function testMergesSameDepartmentAndCareLevelOntoOneLane(): void
    {
        $timezone = new \DateTimeZone('Europe/Berlin');
        $days = new ClosureDayTimelineFactory()->build([
            new ClosureDayTimelineItem(
                new \DateTimeImmutable('2026-05-01 10:00:00', $timezone),
                new \DateTimeImmutable('2026-05-01 12:00:00', $timezone),
                'Cardiology',
                'Inner Medicine',
                'emergency',
                '/interval/1',
                '10:00–12:00 · Cardiology',
                true,
                ClosureEventType::Group,
            ),
            new ClosureDayTimelineItem(
                new \DateTimeImmutable('2026-05-01 14:00:00', $timezone),
                new \DateTimeImmutable('2026-05-01 16:00:00', $timezone),
                'Cardiology',
                'Inner Medicine',
                'emergency',
                '/event/other',
                'Single · 14:00–16:00 · Cardiology',
                false,
                ClosureEventType::Single,
            ),
        ]);

        self::assertCount(1, $days[0]->lanes);
        self::assertCount(2, $days[0]->lanes[0]->segments);
        self::assertFalse($days[0]->lanes[0]->segments[0]->primary);
        self::assertTrue($days[0]->lanes[0]->segments[1]->primary);
        self::assertSame('/event/other', $days[0]->lanes[0]->segments[0]->url);
        self::assertSame(['single'], ClosureDayTimelineFactory::contextEventTypes($days));
    }

    public function testCalendarDayBoundsCoverMidnightToMidnightInBerlin(): void
    {
        $timezone = new \DateTimeZone('Europe/Berlin');
        $bounds = ClosureDayTimelineFactory::calendarDayBounds(
            new \DateTimeImmutable('2026-05-01 10:00:00', $timezone),
            new \DateTimeImmutable('2026-05-01 12:00:00', $timezone),
        );

        self::assertSame('2026-05-01 00:00:00', $bounds->from?->format('Y-m-d H:i:s'));
        self::assertSame('2026-05-02 00:00:00', $bounds->toExclusive?->format('Y-m-d H:i:s'));

        $overnight = ClosureDayTimelineFactory::calendarDayBounds(
            new \DateTimeImmutable('2026-05-01 23:00:00', $timezone),
            new \DateTimeImmutable('2026-05-02 01:00:00', $timezone),
        );
        self::assertSame('2026-05-01 00:00:00', $overnight->from?->format('Y-m-d H:i:s'));
        self::assertSame('2026-05-03 00:00:00', $overnight->toExclusive?->format('Y-m-d H:i:s'));
    }
}
