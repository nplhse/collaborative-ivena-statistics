<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

/**
 * Builds comparable full clock hours inside a reference window.
 * Uncovered days are omitted. An hour that overlaps a closure of the same population is omitted entirely.
 */
final class ClosureVolumeReferenceCalendar
{
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
        $hourFrom = ($dayTimeBucket - 1) * 6;
        $series = array_keys($hourlyBySeries + $blockedBySeries);
        /** @var array<string, array<int, array{0: int, 1: int, 2: int}>> $hours count, sum, blocked */
        $hours = [];
        foreach ($series as $key) {
            for ($hour = $hourFrom; $hour < $hourFrom + 6; ++$hour) {
                $hours[$key][$hour] = [0, 0, 0];
            }
        }
        foreach ($ranges as [$from, $to]) {
            $from = ClosureVolumeClock::wall($from);
            $to = ClosureVolumeClock::wall($to);
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
                    $hourKey = $this->hourInRange($dayKey, $hour, $from, $to);
                    if (null === $hourKey) {
                        continue;
                    }
                    foreach ($series as $key) {
                        if (isset($blockedBySeries[$key][$hourKey])) {
                            ++$hours[$key][$hour][2];
                            continue;
                        }
                        ++$hours[$key][$hour][0];
                        $hours[$key][$hour][1] += $hourlyBySeries[$key][$hourKey] ?? 0;
                    }
                }
            }
        }

        $result = [];
        foreach ($hours as $key => $byHour) {
            $fallback = [0, 0, 0];
            foreach ($byHour as [$count, $sum, $blocked]) {
                $fallback = [$fallback[0] + $count, $fallback[1] + $sum, $fallback[2] + $blocked];
            }
            foreach ($byHour as $hour => [$count, $sum, $blocked]) {
                $result[$key][$hour] = new ClosureVolumeReferenceTally($count, $sum, $blocked, $fallback[0], $fallback[1], $fallback[2]);
            }
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
     * A skipped spring hour does not exist; the repeated autumn hour is one bucket.
     */
    private function hourInRange(string $dayKey, int $hour, \DateTimeImmutable $from, \DateTimeImmutable $to): ?string
    {
        $hourStart = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            sprintf('%s %02d:00:00', $dayKey, $hour),
            ClosureVolumeClock::zone(),
        );
        if (!$hourStart instanceof \DateTimeImmutable) {
            return null;
        }
        if ((int) $hourStart->format('G') !== $hour || $hourStart->format('Y-m-d') !== $dayKey) {
            return null;
        }
        $hourEnd = new \DateTimeImmutable('@'.($hourStart->getTimestamp() + 3600))->setTimezone(ClosureVolumeClock::zone());
        if ($hourStart < $from || $hourEnd > $to) {
            return null;
        }

        return $hourStart->format('Y-m-d H:00:00');
    }
}
