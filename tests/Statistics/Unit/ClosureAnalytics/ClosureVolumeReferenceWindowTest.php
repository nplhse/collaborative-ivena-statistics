<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\ClosureAnalytics\Application\Volume\ClosureCoverage;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeClock;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeMetricKind;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeProvisionalReason;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceCalendar;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceConfig;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceMode;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceSelector;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceWindow;
use PHPUnit\Framework\TestCase;

final class ClosureVolumeReferenceWindowTest extends TestCase
{
    public function testSymmetricWindowExcludesTheEventExtentIncludingGaps(): void
    {
        $window = new ClosureVolumeReferenceWindow(4, 4);
        $ranges = $window->ranges($this->at('2026-05-12 10:00'), $this->at('2026-05-14 08:00'));

        self::assertSame([
            ['2026-04-14 10:00', '2026-05-12 10:00'],
            ['2026-05-14 08:00', '2026-06-11 08:00'],
        ], $this->format($ranges));
    }

    public function testBackwardWindowKeepsAllWeeksBeforeTheEvent(): void
    {
        $configured = new ClosureVolumeReferenceWindow(4, 4);
        $backward = ClosureVolumeReferenceWindow::named(ClosureVolumeReferenceWindow::BACKWARD, $configured);

        self::assertSame(8, $backward->weeksBefore);
        self::assertSame(0, $backward->weeksAfter);
        self::assertSame($configured, ClosureVolumeReferenceWindow::named(ClosureVolumeReferenceWindow::SYMMETRIC, $configured));
        self::assertSame(
            [['2026-03-17 10:00', '2026-05-12 10:00']],
            $this->format($backward->ranges($this->at('2026-05-12 10:00'), $this->at('2026-05-14 08:00'))),
        );
    }

    public function testUnknownOrEmptyWindowsAreRejected(): void
    {
        try {
            ClosureVolumeReferenceWindow::named('forward', new ClosureVolumeReferenceWindow(4, 4));
            self::fail('Unknown window name accepted.');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        new ClosureVolumeReferenceWindow(0, 0);
    }

    public function testLoadRangeSpansBothSidesOfAllEvents(): void
    {
        [$from, $to] = new ClosureVolumeReferenceWindow(4, 4)->loadRange($this->at('2026-05-12 10:00'), $this->at('2026-05-20 00:00'));

        self::assertSame('2026-04-14 10:00', $from->format('Y-m-d H:i'));
        self::assertSame('2026-06-17 00:00', $to->format('Y-m-d H:i'));
    }

    public function testTallyUsesMatchingHoursOnBothSidesAndNeverTheEvent(): void
    {
        // Event Tuesday 12 May 10:00 to Thursday 14 May 08:00; the Tuesday after (19 May) lies after the end.
        $window = new ClosureVolumeReferenceWindow(4, 4);
        $ranges = $window->ranges($this->at('2026-05-12 10:00'), $this->at('2026-05-14 08:00'));
        $covered = [];
        $counts = [];
        foreach (['2026-04-14', '2026-04-21', '2026-04-28', '2026-05-05', '2026-05-12', '2026-05-19', '2026-05-26', '2026-06-02', '2026-06-09'] as $day) {
            $covered[$day] = true;
            $counts[$day.' 10:00:00'] = '2026-05-12' === $day ? 100 : 2;
        }

        $tally = new ClosureVolumeReferenceCalendar()->tallyWindow($ranges, 2, 2, ['m' => $counts], $covered, [])['m'][10];

        // 14 April: 10:00 starts exactly at the window start; 12 May 10:00 is the event start and excluded.
        self::assertSame(8, $tally->primaryCount);
        self::assertSame(16, $tally->primarySum);
        self::assertSame(0, $tally->primaryBlocked);
    }

    public function testBlockingIsPerSeriesAndCountedSeparately(): void
    {
        $ranges = new ClosureVolumeReferenceWindow(4, 4)->ranges($this->at('2026-05-12 10:00'), $this->at('2026-05-12 12:00'));
        $covered = ['2026-05-05' => true, '2026-05-19' => true];
        $counts = ['2026-05-05 10:00:00' => 3, '2026-05-19 10:00:00' => 5];

        $tallies = new ClosureVolumeReferenceCalendar()->tallyWindow(
            $ranges,
            2,
            2,
            ['emergency' => $counts, 'resus' => $counts],
            $covered,
            ['emergency' => ['2026-05-19 10:00:00' => true]],
        );

        self::assertSame(1, $tallies['emergency'][10]->primaryCount);
        self::assertSame(3, $tallies['emergency'][10]->primarySum);
        self::assertSame(1, $tallies['emergency'][10]->primaryBlocked);
        self::assertSame(1, $tallies['emergency'][10]->fallbackBlocked);
        self::assertSame(2, $tallies['resus'][10]->primaryCount);
        self::assertSame(8, $tallies['resus'][10]->primarySum);
        self::assertSame(0, $tallies['resus'][10]->primaryBlocked);
    }

    public function testUncoveredHoursAfterTheEventAreNeitherZeroNorBlocked(): void
    {
        $ranges = new ClosureVolumeReferenceWindow(4, 4)->ranges($this->at('2026-05-12 10:00'), $this->at('2026-05-12 12:00'));

        $tally = new ClosureVolumeReferenceCalendar()->tallyWindow(
            $ranges,
            2,
            2,
            ['m' => []],
            ['2026-05-05' => true],
            ['m' => ['2026-05-19 10:00:00' => true]],
        )['m'][10];

        self::assertSame(1, $tally->primaryCount);
        self::assertSame(0, $tally->primaryBlocked);
    }

    public function testSelectorReportsBlockedSlotsOfTheLevelItUsed(): void
    {
        $selector = new ClosureVolumeReferenceSelector(new ClosureVolumeReferenceConfig(4, 4, 4));
        $ranges = new ClosureVolumeReferenceWindow(4, 4)->ranges($this->at('2026-05-12 10:00'), $this->at('2026-05-12 12:00'));
        $covered = [];
        foreach (['2026-04-21', '2026-04-28', '2026-05-05', '2026-05-19', '2026-05-26'] as $day) {
            $covered[$day] = true;
        }
        $tally = new ClosureVolumeReferenceCalendar()->tallyWindow(
            $ranges,
            2,
            2,
            ['m' => ['2026-05-05 10:00:00' => 4]],
            $covered,
            ['m' => ['2026-05-19 10:00:00' => true, '2026-05-26 10:00:00' => true]],
        )['m'][10];

        $rate = $selector->fromTally($tally);

        self::assertSame(ClosureVolumeReferenceMode::DayTimeBucket, $rate->mode);
        self::assertSame(28, $rate->slotCount);
        self::assertSame(2, $rate->blockedSlotCount);
        self::assertEqualsWithDelta(4 / 28, $rate->hourlyRate ?? 0.0, 0.0001);
    }

    public function testEventStaysProvisionalUntilBothImportsCoverTheWindowAfterIt(): void
    {
        $window = new ClosureVolumeReferenceWindow(4, 4);
        $end = $this->at('2026-05-12 12:00');
        $untilJune = ClosureCoverage::fromRanges([[$this->at('2026-05-01 00:00'), $this->at('2026-07-01 00:00')]]);
        $untilMayEnd = ClosureCoverage::fromRanges([[$this->at('2026-05-01 00:00'), $this->at('2026-06-01 00:00')]]);

        self::assertNull(ClosureVolumeProvisionalReason::forEvent($end, $window, $untilJune, $untilJune));
        self::assertSame(ClosureVolumeProvisionalReason::AwaitingAllocations, ClosureVolumeProvisionalReason::forEvent($end, $window, $untilMayEnd, $untilJune));
        self::assertSame(ClosureVolumeProvisionalReason::AwaitingClosures, ClosureVolumeProvisionalReason::forEvent($end, $window, $untilJune, $untilMayEnd));
        self::assertSame(ClosureVolumeProvisionalReason::AwaitingAllocations, ClosureVolumeProvisionalReason::forEvent($end, $window, null, $untilJune), 'Underived coverage confirms nothing.');
        self::assertNull(ClosureVolumeProvisionalReason::forEvent($end, new ClosureVolumeReferenceWindow(8, 0), $untilMayEnd, $untilMayEnd), 'Without weeks after the event, covering its end is enough.');
    }

    public function testMetricKindsMapUrgenciesAndResources(): void
    {
        self::assertSame(ClosureVolumeMetricKind::Inpatient, ClosureVolumeMetricKind::forUrgency(2));
        self::assertSame([2], ClosureVolumeMetricKind::Inpatient->bindingUrgencies());
        self::assertSame([1, 2, 3], ClosureVolumeMetricKind::Cathlab->bindingUrgencies());
        self::assertTrue(ClosureVolumeMetricKind::Resus->isResource());
        self::assertFalse(ClosureVolumeMetricKind::Emergency->isResource());
        self::assertCount(5, ClosureVolumeMetricKind::all());
        self::assertCount(3, ClosureVolumeMetricKind::urgencyMetrics());
    }

    private function at(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, ClosureVolumeClock::zone());
    }

    /**
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $ranges
     *
     * @return list<array{0: string, 1: string}>
     */
    private function format(array $ranges): array
    {
        return array_map(static fn (array $range): array => [$range[0]->format('Y-m-d H:i'), $range[1]->format('Y-m-d H:i')], $ranges);
    }
}
