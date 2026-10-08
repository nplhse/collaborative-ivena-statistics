<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeBurdenAggregator;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeBurdenSlice;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeClock;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeDeviation;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeHospitalBaseline;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeHourPlanner;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeInterval;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumePopulation;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeQuality;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeRate;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceCalendar;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceConfig;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceMode;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceSelector;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceSlot;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeStratum;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeWindowAggregator;
use PHPUnit\Framework\TestCase;

final class ClosureVolumeReferenceTest extends TestCase
{
    public function testSelectsSameWeekdayAndHourAndIgnoresOtherHours(): void
    {
        $slots = [
            $this->slot('2026-05-05 10:00:00', 4),
            $this->slot('2026-05-12 10:00:00', 6),
            $this->slot('2026-05-19 10:00:00', 2),
            $this->slot('2026-05-26 10:00:00', 8),
            $this->slot('2026-05-05 11:00:00', 100),
            $this->slot('2026-05-06 10:00:00', 100),
        ];

        $rate = $this->selector(4)->select(2, 10, 2, $slots);

        self::assertSame(ClosureVolumeReferenceMode::WeekdayHour, $rate->mode);
        self::assertEqualsWithDelta(5.0, $rate->hourlyRate ?? 0.0, 0.0001);
        self::assertSame(4, $rate->slotCount);
    }

    public function testFallsBackToDayTimeBucketWhenTheExactHourIsSparse(): void
    {
        $slots = [
            $this->slot('2026-05-05 10:00:00', 2),
            $this->slot('2026-05-12 10:00:00', 2),
            $this->slot('2026-05-19 10:00:00', 2),
            $this->slot('2026-05-05 09:00:00', 8),
            $this->slot('2026-05-12 09:00:00', 8),
        ];

        $rate = $this->selector(4)->select(2, 10, 2, $slots);

        self::assertSame(ClosureVolumeReferenceMode::DayTimeBucket, $rate->mode);
        self::assertSame(5, $rate->slotCount);
        self::assertEqualsWithDelta(4.4, $rate->hourlyRate ?? 0.0, 0.0001);
    }

    public function testInsufficientReferenceDoesNotBecomeZero(): void
    {
        $rate = $this->selector(4)->select(2, 10, 2, [
            $this->slot('2026-05-05 10:00:00', 3),
            $this->slot('2026-05-12 09:00:00', 3),
        ]);

        self::assertSame(ClosureVolumeReferenceMode::Insufficient, $rate->mode);
        self::assertNull($rate->hourlyRate);
        self::assertNull($rate->expectedForSeconds(3600));
    }

    public function testZeroExpectationKeepsAbsoluteDeviationAndDropsRelative(): void
    {
        self::assertEqualsWithDelta(4.0, ClosureVolumeDeviation::absolute(4.0, 0.0) ?? 0.0, 0.0001);
        self::assertNull(ClosureVolumeDeviation::relative(4.0, 0.0));
        self::assertNull(ClosureVolumeDeviation::relative(4.0, null));
        self::assertEqualsWithDelta(-2.0, ClosureVolumeDeviation::absolute(8.0, 10.0) ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(-0.2, ClosureVolumeDeviation::relative(8.0, 10.0) ?? 0.0, 0.0001);
    }

    public function testKnownClosureHoursAreLeftOutOfTheReferenceAndUncoveredDaysAreNotZeros(): void
    {
        $zone = ClosureVolumeClock::zone();
        $cutoff = new \DateTimeImmutable('2026-06-02 11:00:00', $zone);
        $covered = [];
        $counts = [];
        foreach (['2026-05-05', '2026-05-12', '2026-05-19', '2026-05-26'] as $day) {
            $covered[$day] = true;
            $counts[$day.' 10:00:00'] = 4;
            $counts[$day.' 09:00:00'] = 4;
        }
        $blocking = ClosureVolumeInterval::fromParts(
            9,
            1,
            4,
            'emergency',
            new \DateTimeImmutable('2026-05-12 10:00:00'),
            new \DateTimeImmutable('2026-05-12 11:00:00'),
        );

        $slots = new ClosureVolumeReferenceCalendar()->build($cutoff, 8, $counts, $covered, [$blocking]);
        $hours = array_map(static fn (ClosureVolumeReferenceSlot $slot): string => $slot->hourStart->format('Y-m-d H:i'), $slots);

        self::assertNotContains('2026-05-12 10:00', $hours);
        self::assertNotContains('2026-04-28 10:00', $hours);
        self::assertContains('2026-05-05 10:00', $hours);
        $ten = array_values(array_filter(
            $slots,
            static fn (ClosureVolumeReferenceSlot $slot): bool => 10 === $slot->hour,
        ));
        self::assertCount(3, $ten);
        $selected = $this->selector(4)->select(2, 10, 2, $slots);
        self::assertSame(ClosureVolumeReferenceMode::DayTimeBucket, $selected->mode);
        $calendar = new ClosureVolumeReferenceCalendar();
        $tallied = $this->selector(4)->fromTallies(...$calendar->tally(
            $cutoff,
            8,
            2,
            10,
            2,
            $counts,
            $covered,
            $calendar->blockedHours([$blocking]),
        ));
        self::assertSame($selected->mode, $tallied->mode);
        self::assertSame($selected->slotCount, $tallied->slotCount);
        self::assertEqualsWithDelta($selected->hourlyRate ?? 0.0, $tallied->hourlyRate ?? 0.0, 0.0001);
    }

    public function testTwelveHourNightClosureIsNotHalfOfTheDay(): void
    {
        $closure = $this->closure('2026-06-02 00:00:00', '2026-06-02 06:00:00');
        $slots = [];
        foreach (range(0, 23) as $hour) {
            for ($week = 1; $week <= 4; ++$week) {
                $day = new \DateTimeImmutable('2026-06-02')->modify(sprintf('-%d weeks', $week))->format('Y-m-d');
                $slots[] = $this->slot(sprintf('%s %02d:00:00', $day, $hour), $hour < 6 ? 0 : 10);
            }
        }
        $drafts = $this->planner()->plan(
            $closure,
            new \DateTimeImmutable('2026-06-03 12:00:00', ClosureVolumeClock::zone()),
            [],
            [],
            $this->rateMap('7|1|base', $slots),
            $this->rateMap('1|base', $slots),
            ['2026-06-02' => true],
            [],
        );
        $during = array_values(array_filter(
            $drafts,
            static fn (\App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeHourDraft $draft): bool => $draft->inClosure && 'base' === $draft->stratum && 1 === $draft->urgencyCode,
        ));

        $expected = 0.0;
        foreach ($during as $draft) {
            $expected += $draft->expectedArea ?? 0.0;
        }
        self::assertEqualsWithDelta(0.0, $expected, 0.0001);
        self::assertNotEqualsWithDelta(90.0, $expected, 0.0001);
    }

    public function testPartialHourUsesItsOwnDuration(): void
    {
        $closure = $this->closure('2026-06-02 10:00:00', '2026-06-02 10:30:00');
        $slots = [];
        for ($week = 1; $week <= 4; ++$week) {
            $day = new \DateTimeImmutable('2026-06-02')->modify(sprintf('-%d weeks', $week))->format('Y-m-d');
            $slots[] = $this->slot($day.' 10:00:00', 10);
        }
        $drafts = $this->planner()->plan(
            $closure,
            new \DateTimeImmutable('2026-06-03 12:00:00', ClosureVolumeClock::zone()),
            ['7|1|base|2026-06-02 10:00:00|2026-06-02 10:30:00' => 3],
            ['1|base|2026-06-02 10:00:00|2026-06-02 10:30:00' => 9],
            $this->rateMap('7|1|base', $slots),
            $this->rateMap('1|base', $slots),
            ['2026-06-02' => true],
            [],
        );
        $during = array_values(array_filter(
            $drafts,
            static fn (\App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeHourDraft $draft): bool => $draft->inClosure && 'base' === $draft->stratum,
        ));

        self::assertCount(1, $during);
        self::assertSame(1800, $during[0]->bucketSeconds);
        self::assertEqualsWithDelta(5.0, $during[0]->expectedArea ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(3.0, $during[0]->observedArea ?? 0.0, 0.0001);
    }

    public function testLaterPartialOfTheSameClockHourKeepsItsOwnCount(): void
    {
        $zone = ClosureVolumeClock::zone();
        $firstStart = new \DateTimeImmutable('2026-06-02 10:10:00', $zone);
        $firstEnd = new \DateTimeImmutable('2026-06-02 10:20:00', $zone);
        $secondStart = new \DateTimeImmutable('2026-06-02 10:40:00', $zone);
        $secondEnd = new \DateTimeImmutable('2026-06-02 10:50:00', $zone);
        $closure = new ClosureVolumeInterval(
            4,
            1,
            7,
            [1],
            $firstStart,
            $secondEnd,
            'emergency',
            0,
            'department',
            4,
            [[$firstStart, $firstEnd], [$secondStart, $secondEnd]],
        );
        $drafts = $this->planner()->plan(
            $closure,
            new \DateTimeImmutable('2026-06-03 12:00:00', $zone),
            [
                '7|1|base|2026-06-02 10:10:00|2026-06-02 10:20:00' => 2,
                '7|1|base|2026-06-02 10:40:00|2026-06-02 10:50:00' => 5,
            ],
            [],
            [],
            [],
            ['2026-06-02' => true],
            [],
        );
        $during = array_values(array_filter(
            $drafts,
            static fn (\App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeHourDraft $draft): bool => $draft->inClosure && 'base' === $draft->stratum && 1 === $draft->urgencyCode,
        ));

        self::assertCount(2, $during);
        self::assertEqualsWithDelta(2.0, $during[0]->observedArea ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(5.0, $during[1]->observedArea ?? 0.0, 0.0001);
    }

    public function testFullHourReadsNestedHourCounts(): void
    {
        $closure = $this->closure('2026-06-02 10:00:00', '2026-06-02 11:00:00');
        $slots = [];
        for ($week = 1; $week <= 4; ++$week) {
            $day = new \DateTimeImmutable('2026-06-02')->modify(sprintf('-%d weeks', $week))->format('Y-m-d');
            $slots[] = $this->slot($day.' 10:00:00', 4);
        }
        $drafts = $this->planner()->plan(
            $closure,
            new \DateTimeImmutable('2026-06-03 12:00:00', ClosureVolumeClock::zone()),
            ['7|1|base' => ['2026-06-02 10:00:00' => 4]],
            ['1|base' => ['2026-06-02 10:00:00' => 9]],
            $this->rateMap('7|1|base', $slots),
            $this->rateMap('1|base', $slots),
            ['2026-06-02' => true],
            [],
        );
        $during = array_values(array_filter(
            $drafts,
            static fn (\App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeHourDraft $draft): bool => $draft->inClosure && 'base' === $draft->stratum && 1 === $draft->urgencyCode,
        ));

        self::assertCount(1, $during);
        self::assertEqualsWithDelta(4.0, $during[0]->observedArea ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(9.0, $during[0]->observedHospital ?? 0.0, 0.0001);
    }

    public function testSpringForwardMeasuresActualSeconds(): void
    {
        $buckets = ClosureVolumeClock::split(
            new \DateTimeImmutable('2026-03-29 01:30:00', ClosureVolumeClock::zone()),
            new \DateTimeImmutable('2026-03-29 03:30:00', ClosureVolumeClock::zone()),
        );

        $seconds = 0;
        foreach ($buckets as $bucket) {
            $seconds += $bucket->seconds;
        }
        self::assertSame(3600, $seconds);
        self::assertNotContains(2, array_map(static fn (\App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeBucket $bucket): int => $bucket->hour, $buckets));
    }

    public function testUncoveredObservationIsNotZeroAndNeighbourInfluencesOnlyTheMargin(): void
    {
        $closure = $this->closure('2026-06-02 10:00:00', '2026-06-02 11:00:00');
        $neighbour = $this->closure('2026-06-02 09:00:00', '2026-06-02 10:00:00', 8);
        $slots = [];
        for ($week = 1; $week <= 4; ++$week) {
            $day = new \DateTimeImmutable('2026-06-02')->modify(sprintf('-%d weeks', $week))->format('Y-m-d');
            $slots[] = $this->slot($day.' 10:00:00', 2);
            $slots[] = $this->slot($day.' 09:00:00', 2);
        }
        $drafts = $this->planner()->plan(
            $closure,
            new \DateTimeImmutable('2026-06-03 12:00:00', ClosureVolumeClock::zone()),
            [],
            [],
            $this->rateMap('7|1|base', $slots),
            $this->rateMap('1|base', $slots),
            ['2026-06-01' => true],
            [$neighbour],
        );
        $base = array_values(array_filter(
            $drafts,
            static fn (\App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeHourDraft $draft): bool => 'base' === $draft->stratum && 1 === $draft->urgencyCode,
        ));
        $during = array_values(array_filter($base, static fn (\App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeHourDraft $draft): bool => $draft->inClosure));
        $pre = array_values(array_filter(
            $base,
            static fn (\App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeHourDraft $draft): bool => !$draft->inClosure && $draft->bucketStart < $closure->startsAt && $draft->influenced,
        ));

        self::assertSame(ClosureVolumeQuality::Incomplete->value, $during[0]->quality);
        self::assertNull($during[0]->observedArea);
        self::assertNotEmpty($pre);
    }

    public function testOngoingClosureHasNoFollowUp(): void
    {
        $closure = $this->closure('2026-06-02 10:00:00', '2026-06-02 12:00:00');
        $drafts = $this->planner()->plan(
            $closure,
            new \DateTimeImmutable('2026-06-02 10:30:00', ClosureVolumeClock::zone()),
            [],
            [],
            [],
            [],
            ['2026-06-02' => true],
            [],
        );
        $post = array_values(array_filter($drafts, static fn (\App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeHourDraft $draft): bool => $draft->bucketStart >= $closure->endsAt));
        self::assertSame([], $post);

        $windows = new ClosureVolumeWindowAggregator()->aggregate(
            $drafts,
            $closure->startsAt,
            $closure->endsAt,
            new \DateTimeImmutable('2026-06-02 10:30:00', ClosureVolumeClock::zone()),
            true,
        );
        $postWindow = $this->window($windows, 'post_1');
        $during = $this->window($windows, 'during');
        self::assertSame(ClosureVolumeQuality::Ongoing, $postWindow->quality);
        self::assertNull($postWindow->observedArea);
        self::assertFalse($during->complete);
        self::assertNull(new ClosureVolumeWindowAggregator()->comparison($windows));
    }

    public function testOverlappingPopulationsAreCountedOnceAndDifferentDepartmentsAdd(): void
    {
        $aggregator = new ClosureVolumeBurdenAggregator();
        $cutoff = new \DateTimeImmutable('2026-06-02 10:00:00');
        $totals = $aggregator->aggregate([
            new ClosureVolumeBurdenSlice(1, 7, 1, 'base', '2026-06-02 10:00:00', 2, 4, 3600, 3600, $cutoff),
            new ClosureVolumeBurdenSlice(1, 7, 1, 'base', '2026-06-02 10:00:00', 9, 9, 3600, 3600, $cutoff->modify('+1 hour')),
            new ClosureVolumeBurdenSlice(1, 8, 1, 'base', '2026-06-02 10:00:00', 1, 1, 3600, 3600, $cutoff),
        ]);

        self::assertEqualsWithDelta(3.0, $totals->observedArea ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(5.0, $totals->expectedArea ?? 0.0, 0.0001);
        self::assertSame(2, $totals->sliceCount);
    }

    public function testHospitalShareUsesSummedNumeratorsAndDenominators(): void
    {
        $share = ClosureVolumeDeviation::share(10 + 10, 100 + 1000);
        $mean = ((10 / 100) + (10 / 1000)) / 2;

        self::assertEqualsWithDelta(20 / 1100, $share ?? 0.0, 0.0000001);
        self::assertNotEqualsWithDelta($mean, $share ?? 0.0, 0.0000001);
        self::assertNull(ClosureVolumeDeviation::share(5.0, 0.0));
    }

    public function testHospitalBaselineKeepsEachHospitalSeparateBeforeTheShare(): void
    {
        $baseline = new ClosureVolumeHospitalBaseline(
            new ClosureVolumeReferenceConfig(8, 4),
            new ClosureVolumeReferenceCalendar(),
            $this->selector(4),
        );
        $first = $baseline->measure(
            new \DateTimeImmutable('2026-06-02 10:00:00'),
            new \DateTimeImmutable('2026-06-02 11:00:00'),
            $this->hospitalCounts(4),
            $this->coveredAround('2026-06-02'),
            ClosureVolumeStratum::Sk1,
        );
        $second = $baseline->measure(
            new \DateTimeImmutable('2026-06-02 10:00:00'),
            new \DateTimeImmutable('2026-06-02 11:00:00'),
            $this->hospitalCounts(8),
            $this->coveredAround('2026-06-02'),
            ClosureVolumeStratum::Sk1,
        );

        self::assertEqualsWithDelta(4.0, $first['expected'] ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(8.0, $second['expected'] ?? 0.0, 0.0001);
        self::assertEqualsWithDelta(12.0, ($first['expected'] ?? 0.0) + ($second['expected'] ?? 0.0), 0.0001);
    }

    public function testCareLevelLimitsThePopulationAndSpecialityIsNotAKey(): void
    {
        self::assertSame([1], ClosureVolumePopulation::urgenciesForCareLevel('emergency'));
        self::assertSame([1, 2, 3], ClosureVolumePopulation::urgenciesForCareLevel('other'));
        self::assertFalse(ClosureVolumePopulation::covers('emergency', ClosureVolumeStratum::Sk2));
        self::assertTrue(ClosureVolumePopulation::covers('other', ClosureVolumeStratum::Sk2));
    }

    public function testCumulativeWindowsOverlapAndInfluencedOnesStayOutOfTheComparison(): void
    {
        $closure = $this->closure('2026-06-02 12:00:00', '2026-06-02 13:00:00');
        $slots = [];
        foreach ([9, 10, 11, 12, 13] as $hour) {
            for ($week = 1; $week <= 4; ++$week) {
                $day = new \DateTimeImmutable('2026-06-02')->modify(sprintf('-%d weeks', $week))->format('Y-m-d');
                $slots[] = $this->slot(sprintf('%s %02d:00:00', $day, $hour), 2);
            }
        }
        $neighbour = $this->closure('2026-06-02 11:00:00', '2026-06-02 12:00:00', 8);
        $drafts = $this->planner()->plan(
            $closure,
            new \DateTimeImmutable('2026-06-03 00:00:00', ClosureVolumeClock::zone()),
            [
                '7|1|base|2026-06-02 10:00:00|2026-06-02 11:00:00' => 4,
                '7|1|base|2026-06-02 11:00:00|2026-06-02 12:00:00' => 2,
                '7|1|base|2026-06-02 12:00:00|2026-06-02 13:00:00' => 1,
            ],
            ['1|base|2026-06-02 12:00:00|2026-06-02 13:00:00' => 6],
            $this->rateMap('7|1|base', $slots),
            $this->rateMap('1|base', $slots),
            ['2026-06-02' => true],
            [$neighbour],
        );
        $base = array_values(array_filter(
            $drafts,
            static fn (\App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeHourDraft $draft): bool => 'base' === $draft->stratum && 1 === $draft->urgencyCode,
        ));
        $aggregator = new ClosureVolumeWindowAggregator();
        $windows = $aggregator->aggregate($base, $closure->startsAt, $closure->endsAt, new \DateTimeImmutable('2026-06-03 00:00:00', ClosureVolumeClock::zone()), true);
        $pre1 = $this->window($windows, 'pre_1');
        $pre3 = $this->window($windows, 'pre_3');

        self::assertGreaterThan($pre1->observedArea ?? 0.0, $pre3->observedArea ?? 0.0);
        self::assertTrue($pre1->influenced);
        self::assertSame(ClosureVolumeQuality::Influenced, $pre1->quality);
        self::assertNull($aggregator->comparison($windows));
        self::assertSame(6.0, $this->window($windows, 'during')->observedHospital);
    }

    private function selector(int $minimum): ClosureVolumeReferenceSelector
    {
        return new ClosureVolumeReferenceSelector(new ClosureVolumeReferenceConfig(8, $minimum));
    }

    private function planner(): ClosureVolumeHourPlanner
    {
        return new ClosureVolumeHourPlanner();
    }

    /**
     * @param list<ClosureVolumeReferenceSlot> $slots
     *
     * @return array<string, ClosureVolumeRate>
     */
    private function rateMap(string $series, array $slots): array
    {
        $selector = $this->selector(4);
        $rates = [];
        foreach ($slots as $slot) {
            $key = $series.'|'.$slot->weekday.'|'.$slot->hour;
            if (isset($rates[$key])) {
                continue;
            }
            $rates[$key] = $selector->select($slot->weekday, $slot->hour, $slot->dayTimeBucket, $slots);
        }

        return $rates;
    }

    private function slot(string $at, int $count): ClosureVolumeReferenceSlot
    {
        $start = new \DateTimeImmutable($at, ClosureVolumeClock::zone());

        return new ClosureVolumeReferenceSlot(
            $start,
            (int) $start->format('N'),
            (int) $start->format('G'),
            ClosureVolumeClock::dayTimeBucket((int) $start->format('G')),
            $count,
        );
    }

    private function closure(string $start, string $end, int $id = 4): ClosureVolumeInterval
    {
        return ClosureVolumeInterval::fromParts(
            $id,
            1,
            7,
            'emergency',
            new \DateTimeImmutable($start),
            new \DateTimeImmutable($end),
        );
    }

    /**
     * @param list<\App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeWindowResult> $windows
     */
    private function window(array $windows, string $kind): \App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeWindowResult
    {
        foreach ($windows as $window) {
            if ($window->kind === $kind) {
                return $window;
            }
        }

        self::fail('Missing window '.$kind);
    }

    /**
     * @return array<string, int>
     */
    private function hospitalCounts(int $count): array
    {
        $counts = [];
        for ($week = 1; $week <= 4; ++$week) {
            $day = new \DateTimeImmutable('2026-06-02')->modify(sprintf('-%d weeks', $week))->format('Y-m-d');
            $counts['1|base|'.$day.' 10:00:00'] = $count;
        }
        $counts['1|base|2026-06-02 10:00:00'] = $count;

        return $counts;
    }

    /**
     * @return array<string, true>
     */
    private function coveredAround(string $day): array
    {
        $covered = [$day => true];
        for ($week = 1; $week <= 4; ++$week) {
            $covered[new \DateTimeImmutable($day)->modify(sprintf('-%d weeks', $week))->format('Y-m-d')] = true;
        }

        return $covered;
    }
}
