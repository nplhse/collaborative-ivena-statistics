<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeChartFactory;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeChartFocus;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeClock;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeHourDraft;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeMetricKind;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeRelevance;
use PHPUnit\Framework\TestCase;

final class ClosureVolumeChartFactoryTest extends TestCase
{
    public function testShortClosureIsShownCompletely(): void
    {
        [$drafts, $focus] = $this->closure('2026-07-01 10:00', '2026-07-01 12:00');

        self::assertNull($focus);
        $chart = new ClosureVolumeChartFactory()->build($drafts, $focus);

        self::assertCount(14, $chart['categories']);
        self::assertNull($chart['gapIndex']);
        self::assertNull($chart['hiddenHours']);
        self::assertSame(6, $chart['closureStartIndex']);
        self::assertSame(7, $chart['closureEndIndex']);
        self::assertSame('01.07. 10:00', $chart['categories'][6]);
        self::assertSame('2026-07-01 11:00:00', $chart['ends'][6]);
    }

    public function testSixHourClosureNeedsNoGap(): void
    {
        [, $focus] = $this->closure('2026-07-01 10:00', '2026-07-01 16:00');

        self::assertNull($focus);
    }

    public function testLongerClosureHidesItsMiddleBehindOneGapPoint(): void
    {
        [$drafts, $focus] = $this->closure('2026-07-01 10:00', '2026-07-01 17:00');

        self::assertInstanceOf(ClosureVolumeChartFocus::class, $focus);
        $chart = new ClosureVolumeChartFactory()->build($drafts, $focus);

        self::assertCount(6 + 3 + 1 + 3 + 6, $chart['categories']);
        self::assertSame(9, $chart['gapIndex']);
        self::assertSame(1, $chart['hiddenHours']);
        self::assertSame(ClosureVolumeChartFactory::GAP_CATEGORY, $chart['categories'][9]);
        self::assertNull($chart['observed'][9]);
        self::assertNull($chart['expected'][9]);
        self::assertNull($chart['areaRatio'][9]);
        self::assertSame('01.07. 14:00', $chart['categories'][10]);
        self::assertSame(6, $chart['closureStartIndex']);
        self::assertSame(12, $chart['closureEndIndex']);
        self::assertSame([[6, 12]], $chart['closureBands'], 'The closure band runs through the gap.');
    }

    public function testFullDayClosureKeepsPartialHoursAndAlignsBarSeries(): void
    {
        [$drafts, $focus] = $this->closure('2026-07-01 10:30', '2026-07-02 10:30');

        self::assertInstanceOf(ClosureVolumeChartFocus::class, $focus);
        $factory = new ClosureVolumeChartFactory();
        $chart = $factory->build($drafts, $focus);
        $bars = $factory->buildBarSeries($drafts, $focus);

        self::assertSame(17, $chart['hiddenHours'], '14:00 to 07:00: the buckets around 13:30 and 07:30 stay visible.');
        self::assertSame('01.07. 13:00', $chart['categories'][$chart['gapIndex'] - 1]);
        self::assertSame('02.07. 07:00', $chart['categories'][$chart['gapIndex'] + 1]);
        self::assertSame('01.07. 10:30', $chart['categories'][$chart['closureStartIndex']]);
        self::assertCount(\count($chart['categories']), $bars['sk1']);
        self::assertNull($bars['sk1'][$chart['gapIndex']]);
        self::assertSame(1.0, $bars['sk1'][$chart['gapIndex'] + 1]);
    }

    public function testRunningClosureEndsAtNow(): void
    {
        $start = $this->at('2026-07-01 10:00');
        $focus = ClosureVolumeChartFocus::around($start, $this->at('2026-07-02 08:00'), $this->at('2026-07-01 20:00'));

        self::assertInstanceOf(ClosureVolumeChartFocus::class, $focus);
        self::assertSame('2026-07-01 13:00', $focus->hideFrom->format('Y-m-d H:i'));
        self::assertSame('2026-07-01 17:00', $focus->hideUntil->format('Y-m-d H:i'));
        self::assertTrue($focus->hides($this->at('2026-07-01 13:00'), $this->at('2026-07-01 14:00')));
        self::assertFalse($focus->hides($this->at('2026-07-01 12:00'), $this->at('2026-07-01 13:00')));
        self::assertFalse($focus->hides($this->at('2026-07-01 16:30'), $this->at('2026-07-01 17:30')), 'A bucket on the edge stays visible.');
    }

    /**
     * Drafts like the projection writes them: six hours of context on each side, split on clock hours.
     *
     * @return array{0: list<ClosureVolumeHourDraft>, 1: ?ClosureVolumeChartFocus}
     */
    private function closure(string $startsAt, string $endsAt): array
    {
        $start = $this->at($startsAt);
        $end = $this->at($endsAt);
        $drafts = [];
        foreach ([
            [false, $start->modify('-6 hours'), $start],
            [true, $start, $end],
            [false, $end, $end->modify('+6 hours')],
        ] as [$inClosure, $from, $until]) {
            foreach (ClosureVolumeClock::split($from, $until) as $bucket) {
                $drafts[] = $this->draft($bucket->start, $bucket->end, $inClosure);
            }
        }

        return [$drafts, ClosureVolumeChartFocus::around($start, $end, $end->modify('+1 day'))];
    }

    private function draft(\DateTimeImmutable $start, \DateTimeImmutable $end, bool $inClosure): ClosureVolumeHourDraft
    {
        $seconds = $end->getTimestamp() - $start->getTimestamp();

        return new ClosureVolumeHourDraft(
            1,
            1,
            'department',
            7,
            11,
            ClosureVolumeMetricKind::Emergency,
            $start,
            $end,
            $inClosure,
            1.0,
            2.0,
            3.0,
            4.0,
            $seconds,
            $seconds,
            4,
            0,
            8,
            'weekday_hour',
            false,
            'reliable',
            ClosureVolumeRelevance::Closed,
            1,
            false,
            null,
            $start->modify('-1 day'),
        );
    }

    private function at(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, ClosureVolumeClock::zone());
    }
}
