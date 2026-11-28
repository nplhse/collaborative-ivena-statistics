<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeAnchor;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeChartFactory;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeClock;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeHourDraft;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeHourPlanner;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeMetricKind;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeNeighbourIndex;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeQuality;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeRate;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceConfig;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceSelector;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceSlot;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeRelevance;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeWindowAggregator;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeWindowResult;
use PHPUnit\Framework\TestCase;

/**
 * Windows around a closure that does not start on a full hour (#1018).
 *
 * Closure: Tuesday 2 June 2026, 15:25–21:19 (Europe/Berlin), department 7, SK1. Reference: one allocation in every
 * clock hour of the four weeks before, so 1.0 expected allocation per hour. Allocations around the closure:
 * 12:10 (before the −3 h window, which starts at 12:25), 12:40, two in the hour 13:00, 14:50, and after the end
 * 21:40 and 23:30 (the +1 h window ends at 22:19, the +3 h window at 00:19).
 *
 * By hand:
 * - −1 h  [14:25, 15:25): expected 1.0, observed 1 (14:50)
 * - −3 h  [12:25, 15:25): expected 3.0, observed 4 (12:40, 2 × 13:xx, 14:50)
 * - −6 h  [09:25, 15:25): expected 6.0, observed 5 (also 12:10)
 * - +1 h  [21:19, 22:19): expected 1.0, observed 1 (21:40)
 * - +3 h  [21:19, 00:19): expected 3.0, observed 2 (also 23:30)
 * Every window is complete. Before #1018 the projection only cut at full hours, so −3 h lost 12:00–13:00 and was
 * incomplete with 145 of 180 minutes, and −1 h kept only 15:00–15:25.
 */
final class ClosureVolumeWindowEdgesTest extends TestCase
{
    private const string SERIES = 'd|7|emergency';

    public function testWindowsAroundAnOffHourClosureAreCompleteAndCountExactly(): void
    {
        $closure = $this->closure();
        $drafts = $this->drafts($closure);
        $windows = new ClosureVolumeWindowAggregator()->aggregate(
            $drafts,
            $closure->startsAt,
            $closure->endsAt,
            $this->now(),
            true,
        );

        $expected = [
            'pre_1' => [1.0, 1.0, 3600],
            'pre_3' => [3.0, 4.0, 10800],
            'pre_6' => [6.0, 5.0, 21600],
            'post_1' => [1.0, 1.0, 3600],
            'post_3' => [3.0, 2.0, 10800],
        ];
        foreach ($expected as $kind => [$expectedArea, $observedArea, $seconds]) {
            $window = $this->window($windows, $kind);
            self::assertTrue($window->complete, $kind);
            self::assertSame($seconds, $window->evaluableSeconds, $kind);
            self::assertSame($seconds, $window->requestedSeconds, $kind);
            self::assertNotSame(ClosureVolumeQuality::Incomplete, $window->quality, $kind);
            self::assertEqualsWithDelta($expectedArea, $window->expectedArea ?? -1.0, 0.0001, $kind);
            self::assertEqualsWithDelta($observedArea, $window->observedArea ?? -1.0, 0.0001, $kind);
        }
    }

    public function testThePlannerCutsTheContextAtTheWindowEdges(): void
    {
        $starts = array_map(
            static fn (ClosureVolumeHourDraft $draft): string => $draft->bucketStart->format('H:i'),
            array_values(array_filter($this->drafts($this->closure()), static fn (ClosureVolumeHourDraft $draft): bool => !$draft->inClosure)),
        );

        self::assertSame(
            ['09:25', '10:00', '11:00', '12:00', '12:25', '13:00', '14:00', '14:25', '15:00', '21:19', '22:00', '22:19', '23:00', '00:00', '00:19', '01:00', '02:00', '03:00'],
            $starts,
        );
    }

    public function testTheChartShowsOnePointPerClockHourOutsideTheClosure(): void
    {
        $chart = new ClosureVolumeChartFactory()->build($this->drafts($this->closure()));

        // 12:00–12:25 and 12:25–13:00 are one chart point again: 12:10 and 12:40, observed 2.
        $index = array_search('2026-06-02 12:00:00', $chart['starts'], true);
        self::assertIsInt($index);
        self::assertSame('2026-06-02 13:00:00', $chart['ends'][$index]);
        self::assertSame(2.0, $chart['observed'][$index]);
        self::assertSame(1.0, $chart['expected'][$index]);
        self::assertNotContains('2026-06-02 12:25:00', $chart['starts']);
        self::assertNotContains('2026-06-02 14:25:00', $chart['starts']);
    }

    /**
     * @return list<ClosureVolumeHourDraft>
     */
    private function drafts(ClosureVolumeAnchor $closure): array
    {
        $counts = [
            self::SERIES.'|2026-06-02 12:00:00|2026-06-02 12:25:00' => 1,
            self::SERIES.'|2026-06-02 12:25:00|2026-06-02 13:00:00' => 1,
            self::SERIES.'|2026-06-02 14:25:00|2026-06-02 15:00:00' => 1,
            self::SERIES.'|2026-06-02 21:19:00|2026-06-02 22:00:00' => 1,
            self::SERIES => ['2026-06-02 13:00:00' => 2, '2026-06-02 23:00:00' => 1],
        ];
        $rates = $this->hourlyRateOfOne();

        return new ClosureVolumeHourPlanner()->plan(
            $closure,
            $this->now(),
            $counts,
            $rates,
            $rates,
            ['2026-06-02' => true, '2026-06-03' => true],
            ClosureVolumeNeighbourIndex::fromIntervals([]),
            null,
        );
    }

    /**
     * One allocation in every clock hour of the four Tuesdays and Wednesdays before.
     *
     * @return array<string, ClosureVolumeRate>
     */
    private function hourlyRateOfOne(): array
    {
        $slots = [];
        foreach (['2026-06-02', '2026-06-03'] as $date) {
            for ($week = 1; $week <= 4; ++$week) {
                $day = new \DateTimeImmutable($date)->modify(sprintf('-%d weeks', $week))->format('Y-m-d');
                foreach (range(0, 23) as $hour) {
                    $start = new \DateTimeImmutable(sprintf('%s %02d:00:00', $day, $hour), ClosureVolumeClock::zone());
                    $slots[] = new ClosureVolumeReferenceSlot($start, (int) $start->format('N'), $hour, ClosureVolumeClock::dayTimeBucket($hour), 1);
                }
            }
        }
        $selector = new ClosureVolumeReferenceSelector(new ClosureVolumeReferenceConfig(8, 0, 4));
        $rates = [];
        foreach ($slots as $slot) {
            $rates[$slot->weekday.'|'.$slot->hour] ??= $selector->select($slot->weekday, $slot->hour, $slot->dayTimeBucket, $slots);
        }

        return $rates;
    }

    private function closure(): ClosureVolumeAnchor
    {
        $zone = ClosureVolumeClock::zone();

        return new ClosureVolumeAnchor(
            4,
            1,
            'department',
            0,
            7,
            ClosureVolumeMetricKind::Emergency,
            ClosureVolumeRelevance::Closed,
            1,
            [[new \DateTimeImmutable('2026-06-02 15:25:00', $zone), new \DateTimeImmutable('2026-06-02 21:19:00', $zone)]],
        );
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-06-04 12:00:00', ClosureVolumeClock::zone());
    }

    /**
     * @param list<ClosureVolumeWindowResult> $windows
     */
    private function window(array $windows, string $kind): ClosureVolumeWindowResult
    {
        foreach ($windows as $window) {
            if ($window->kind === $kind) {
                return $window;
            }
        }

        self::fail('Missing window '.$kind);
    }
}
