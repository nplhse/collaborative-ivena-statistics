<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

/**
 * Builds comparable full clock hours inside a reference window.
 * Uncovered days are omitted. An hour that overlaps a closure of the same population is omitted entirely.
 */
final class ClosureVolumeReferenceCalendar
{
    private const int CLOCK_HOUR_CACHE = 20000;

    /** @var array<string, array{0: int, 1: int, 2: string}|null> */
    private array $clockHours = [];

    /**
     * @param array<string, int>          $hourlyCounts key Y-m-d H:00:00
     * @param array<string, true>         $coveredDays
     * @param list<ClosureVolumeInterval> $blocking
     *
     * @return list<ClosureVolumeReferenceSlot>
     */
    public function build(
        \DateTimeImmutable $cutoff,
        int $weeks,
        array $hourlyCounts,
        array $coveredDays,
        array $blocking,
    ): array {
        $cutoff = ClosureVolumeClock::wall($cutoff);
        $refStart = $cutoff->modify(sprintf('-%d weeks', $weeks));
        $blockedHours = $this->blockedHours($blocking);
        $slots = [];
        $day = $refStart->setTime(0, 0);
        $lastDay = $cutoff->setTime(0, 0);

        while ($day <= $lastDay) {
            $dayKey = $day->format('Y-m-d');
            if (isset($coveredDays[$dayKey])) {
                for ($hour = 0; $hour < 24; ++$hour) {
                    $slot = $this->slot($dayKey, $hour, $refStart, $cutoff, $hourlyCounts, $blockedHours);
                    if ($slot instanceof ClosureVolumeReferenceSlot) {
                        $slots[] = $slot;
                    }
                }
            }
            $day = $day->modify('+1 day');
        }

        return $slots;
    }

    /**
     * One calendar walk per weekday and day-time block over every window range and series.
     * A slot must lie fully inside one range; uncovered days are skipped, and a covered hour
     * blocked for a series is counted as blocked instead of entering that series' reference.
     *
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $ranges          wall-clock [from, to)
     * @param array<string, array<string, int>>                         $hourlyBySeries  series => Y-m-d H:00:00 => count
     * @param array<string, true>                                       $coveredDays
     * @param array<string, array<string, true>>                        $blockedBySeries series => Y-m-d H:00:00 => true
     *
     * @return array<string, array<int, ClosureVolumeReferenceTally>> series => hour => tally
     */
    public function tallyWindow(
        array $ranges,
        int $weekday,
        int $dayTimeBucket,
        array $hourlyBySeries,
        array $coveredDays,
        array $blockedBySeries,
    ): array {
        $slots = $this->windowSlots($ranges, $weekday, $dayTimeBucket, $coveredDays);
        $result = [];
        foreach (array_keys($hourlyBySeries + $blockedBySeries) as $series) {
            $result[$series] = $this->tallySlots($slots, $dayTimeBucket, $hourlyBySeries[$series] ?? [], $blockedBySeries[$series] ?? []);
        }

        return $result;
    }

    /**
     * Covered hours of one weekday and day-time block that lie fully inside one range.
     * The list depends on the event extent, not on the series, so callers can reuse it.
     *
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $ranges      wall-clock [from, to)
     * @param array<string, true>                                       $coveredDays
     *
     * @return list<array{0: int, 1: string}> clock hour, Y-m-d H:00:00
     */
    public function windowSlots(array $ranges, int $weekday, int $dayTimeBucket, array $coveredDays): array
    {
        $hourFrom = ($dayTimeBucket - 1) * 6;
        $slots = [];
        foreach ($ranges as [$from, $to]) {
            $from = ClosureVolumeClock::wall($from);
            $to = ClosureVolumeClock::wall($to);
            $fromTs = $from->getTimestamp();
            $toTs = $to->getTimestamp();
            $day = $from->setTime(0, 0);
            $shift = ($weekday - (int) $day->format('N') + 7) % 7;
            if (0 !== $shift) {
                $day = $day->modify(sprintf('+%d days', $shift));
            }
            for (; $day < $to; $day = $day->modify('+7 days')) {
                $dayKey = $day->format('Y-m-d');
                if (!isset($coveredDays[$dayKey])) {
                    continue;
                }
                for ($hour = $hourFrom; $hour < $hourFrom + 6; ++$hour) {
                    $slot = $this->clockHour($dayKey, $hour);
                    if (null !== $slot && $slot[0] >= $fromTs && $slot[1] <= $toTs) {
                        $slots[] = [$hour, $slot[2]];
                    }
                }
            }
        }

        return $slots;
    }

    /**
     * @param list<array{0: int, 1: string}> $slots   from {@see self::windowSlots()}
     * @param array<string, int>             $hourly  Y-m-d H:00:00 => count
     * @param array<string, true>            $blocked Y-m-d H:00:00 => true
     *
     * @return array<int, ClosureVolumeReferenceTally> hour => tally
     */
    public function tallySlots(array $slots, int $dayTimeBucket, array $hourly, array $blocked): array
    {
        $hourFrom = ($dayTimeBucket - 1) * 6;
        /** @var array<int, array{0: int, 1: int, 2: int}> $hours count, sum, blocked */
        $hours = [];
        for ($hour = $hourFrom; $hour < $hourFrom + 6; ++$hour) {
            $hours[$hour] = [0, 0, 0];
        }
        foreach ($slots as [$hour, $key]) {
            if (isset($blocked[$key])) {
                ++$hours[$hour][2];
                continue;
            }
            ++$hours[$hour][0];
            $hours[$hour][1] += $hourly[$key] ?? 0;
        }
        $fallback = [0, 0, 0];
        foreach ($hours as [$count, $sum, $blockedCount]) {
            $fallback = [$fallback[0] + $count, $fallback[1] + $sum, $fallback[2] + $blockedCount];
        }
        $result = [];
        foreach ($hours as $hour => [$count, $sum, $blockedCount]) {
            $result[$hour] = new ClosureVolumeReferenceTally($count, $sum, $blockedCount, $fallback[0], $fallback[1], $fallback[2]);
        }

        return $result;
    }

    /**
     * Hours outside the partition window cannot change a reference slot, so an open-ended
     * closure is clipped before it is expanded into hourly keys.
     *
     * @param list<ClosureVolumeInterval> $blocking
     *
     * @return array<string, true> key Y-m-d H:00:00
     */
    public function blockedHours(
        array $blocking,
        ?\DateTimeImmutable $notBefore = null,
        ?\DateTimeImmutable $notAfter = null,
    ): array {
        $blocked = [];
        foreach ($blocking as $interval) {
            $start = $interval->startsAt;
            $end = $interval->endsAt;
            if ($notBefore instanceof \DateTimeImmutable && $start < $notBefore) {
                $start = $notBefore;
            }
            if ($notAfter instanceof \DateTimeImmutable && $end > $notAfter) {
                $end = $notAfter;
            }
            if ($start >= $end) {
                continue;
            }
            foreach (ClosureVolumeClock::split($start, $end) as $bucket) {
                $blocked[$bucket->start->format('Y-m-d H').':00:00'] = true;
            }
        }

        return $blocked;
    }

    /**
     * @param array<string, int>  $hourlyCounts
     * @param array<string, true> $blockedHours
     */
    private function slot(
        string $dayKey,
        int $hour,
        \DateTimeImmutable $refStart,
        \DateTimeImmutable $cutoff,
        array $hourlyCounts,
        array $blockedHours,
    ): ?ClosureVolumeReferenceSlot {
        $count = $this->comparableCount($dayKey, $hour, $refStart, $cutoff, $hourlyCounts, $blockedHours);
        $hourStart = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            sprintf('%s %02d:00:00', $dayKey, $hour),
            ClosureVolumeClock::zone(),
        );
        if (null === $count || !$hourStart instanceof \DateTimeImmutable) {
            return null;
        }

        return new ClosureVolumeReferenceSlot(
            $hourStart,
            (int) $hourStart->format('N'),
            $hour,
            ClosureVolumeClock::dayTimeBucket($hour),
            $count,
        );
    }

    /**
     * @param array<string, int>  $hourlyCounts
     * @param array<string, true> $blockedHours
     */
    private function comparableCount(
        string $dayKey,
        int $hour,
        \DateTimeImmutable $refStart,
        \DateTimeImmutable $cutoff,
        array $hourlyCounts,
        array $blockedHours,
    ): ?int {
        $hourKey = $this->hourInRange($dayKey, $hour, $refStart, $cutoff);
        if (null === $hourKey || isset($blockedHours[$hourKey])) {
            return null;
        }

        return $hourlyCounts[$hourKey] ?? 0;
    }

    /**
     * Key of a wall-clock hour that exists on that day and lies fully inside [from, to).
     */
    private function hourInRange(string $dayKey, int $hour, \DateTimeImmutable $from, \DateTimeImmutable $to): ?string
    {
        $slot = $this->clockHour($dayKey, $hour);
        if (null === $slot || $slot[0] < $from->getTimestamp() || $slot[1] > $to->getTimestamp()) {
            return null;
        }

        return $slot[2];
    }

    /**
     * Start and end instant and key of a wall-clock hour, or null when the hour does not exist
     * (skipped spring hour). The repeated autumn hour is its first pass, one hour long.
     * Calendar hours never change, so they are cached; the cache is dropped when it grows large.
     *
     * @return array{0: int, 1: int, 2: string}|null
     */
    private function clockHour(string $dayKey, int $hour): ?array
    {
        $key = $dayKey.'|'.$hour;
        if (\array_key_exists($key, $this->clockHours)) {
            return $this->clockHours[$key];
        }
        if (\count($this->clockHours) >= self::CLOCK_HOUR_CACHE) {
            $this->clockHours = [];
        }
        $hourStart = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            sprintf('%s %02d:00:00', $dayKey, $hour),
            ClosureVolumeClock::zone(),
        );
        $slot = null;
        if ($hourStart instanceof \DateTimeImmutable && (int) $hourStart->format('G') === $hour && $hourStart->format('Y-m-d') === $dayKey) {
            $start = $hourStart->getTimestamp();
            $slot = [$start, $start + 3600, $hourStart->format('Y-m-d H:00:00')];
        }

        return $this->clockHours[$key] = $slot;
    }
}
