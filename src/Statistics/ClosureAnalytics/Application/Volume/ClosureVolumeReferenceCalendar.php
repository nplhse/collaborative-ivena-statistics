<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

/**
 * Builds comparable full clock hours before a cutoff.
 * Uncovered days are omitted. A hour that overlaps a closure of the same population is omitted entirely.
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
     * Comparable hours of one weekday and day-time bucket inside the reference window.
     * Hours listed in $blockedHours are omitted entirely.
     *
     * @param array<string, int>  $hourlyCounts key Y-m-d H:00:00
     * @param array<string, true> $coveredDays
     * @param array<string, true> $blockedHours key Y-m-d H:00:00
     *
     * @return list<ClosureVolumeReferenceSlot>
     */
    public function matchingSlots(
        \DateTimeImmutable $cutoff,
        int $weeks,
        int $weekday,
        int $dayTimeBucket,
        array $hourlyCounts,
        array $coveredDays,
        array $blockedHours,
    ): array {
        $cutoff = ClosureVolumeClock::wall($cutoff);
        $refStart = $cutoff->modify(sprintf('-%d weeks', $weeks));
        $hourFrom = ($dayTimeBucket - 1) * 6;
        $day = $cutoff->setTime(0, 0);
        $shift = ((int) $day->format('N') - $weekday + 7) % 7;
        if (0 !== $shift) {
            $day = $day->modify(sprintf('-%d days', $shift));
        }
        $first = $refStart->setTime(0, 0);
        $slots = [];
        for ($week = 0; $week <= $weeks && $day >= $first; ++$week) {
            $dayKey = $day->format('Y-m-d');
            if (isset($coveredDays[$dayKey])) {
                for ($hour = $hourFrom; $hour < $hourFrom + 6; ++$hour) {
                    $slot = $this->slot($dayKey, $hour, $refStart, $cutoff, $hourlyCounts, $blockedHours);
                    if ($slot instanceof ClosureVolumeReferenceSlot) {
                        $slots[] = $slot;
                    }
                }
            }
            $day = $day->modify('-7 days');
        }

        return $slots;
    }

    /**
     * Counts of the exact hour and of its day-time bucket inside the reference window.
     * The result matches {@see self::matchingSlots()} followed by the reference selector.
     *
     * @param array<string, int>  $hourlyCounts key Y-m-d H:00:00
     * @param array<string, true> $coveredDays
     * @param array<string, true> $blockedHours key Y-m-d H:00:00
     *
     * @return array{0: int, 1: int, 2: int, 3: int} primary count, primary sum, fallback count, fallback sum
     */
    public function tally(
        \DateTimeImmutable $cutoff,
        int $weeks,
        int $weekday,
        int $hour,
        int $dayTimeBucket,
        array $hourlyCounts,
        array $coveredDays,
        array $blockedHours,
    ): array {
        $cutoff = ClosureVolumeClock::wall($cutoff);
        $refStart = $cutoff->modify(sprintf('-%d weeks', $weeks));
        $hourFrom = ($dayTimeBucket - 1) * 6;
        $day = $cutoff->setTime(0, 0);
        $shift = ((int) $day->format('N') - $weekday + 7) % 7;
        if (0 !== $shift) {
            $day = $day->modify(sprintf('-%d days', $shift));
        }
        $first = $refStart->setTime(0, 0);
        $primaryCount = 0;
        $primarySum = 0;
        $fallbackCount = 0;
        $fallbackSum = 0;
        for ($week = 0; $week <= $weeks && $day >= $first; ++$week) {
            $dayKey = $day->format('Y-m-d');
            if (isset($coveredDays[$dayKey])) {
                for ($candidate = $hourFrom; $candidate < $hourFrom + 6; ++$candidate) {
                    $count = $this->comparableCount($dayKey, $candidate, $refStart, $cutoff, $hourlyCounts, $blockedHours);
                    if (null === $count) {
                        continue;
                    }
                    ++$fallbackCount;
                    $fallbackSum += $count;
                    if ($candidate === $hour) {
                        ++$primaryCount;
                        $primarySum += $count;
                    }
                }
            }
            $day = $day->modify('-7 days');
        }

        return [$primaryCount, $primarySum, $fallbackCount, $fallbackSum];
    }

    /**
     * One calendar walk for every hour of a day-time block and every stratum.
     * The fallback totals are identical for each hour of the block.
     *
     * @param array<string, array<string, int>> $hourlyByStratum stratum => hour key => count
     * @param array<string, true>               $coveredDays
     * @param array<string, true>               $blockedHours
     *
     * @return array<string, array<int, array{0: int, 1: int, 2: int, 3: int}>>
     */
    public function tallyBlock(
        \DateTimeImmutable $cutoff,
        int $weeks,
        int $weekday,
        int $dayTimeBucket,
        array $hourlyByStratum,
        array $coveredDays,
        array $blockedHours,
    ): array {
        $cutoff = ClosureVolumeClock::wall($cutoff);
        $refStart = $cutoff->modify(sprintf('-%d weeks', $weeks));
        $hourFrom = ($dayTimeBucket - 1) * 6;
        $day = $cutoff->setTime(0, 0);
        $shift = ((int) $day->format('N') - $weekday + 7) % 7;
        if (0 !== $shift) {
            $day = $day->modify(sprintf('-%d days', $shift));
        }
        $first = $refStart->setTime(0, 0);
        $strata = array_keys($hourlyByStratum);
        if ([] === $strata) {
            $strata = ['base', 'resus', 'cathlab'];
        }
        /** @var array<string, array{count: int, sum: int}> $fallback */
        $fallback = [];
        /** @var array<string, array<int, array{count: int, sum: int}>> $primary */
        $primary = [];
        foreach ($strata as $stratum) {
            $fallback[$stratum] = ['count' => 0, 'sum' => 0];
            for ($hour = $hourFrom; $hour < $hourFrom + 6; ++$hour) {
                $primary[$stratum][$hour] = ['count' => 0, 'sum' => 0];
            }
        }
        for ($week = 0; $week <= $weeks && $day >= $first; ++$week) {
            $dayKey = $day->format('Y-m-d');
            if (isset($coveredDays[$dayKey])) {
                for ($candidate = $hourFrom; $candidate < $hourFrom + 6; ++$candidate) {
                    foreach ($strata as $stratum) {
                        $count = $this->comparableCount(
                            $dayKey,
                            $candidate,
                            $refStart,
                            $cutoff,
                            $hourlyByStratum[$stratum] ?? [],
                            $blockedHours,
                        );
                        if (null === $count) {
                            continue;
                        }
                        ++$fallback[$stratum]['count'];
                        $fallback[$stratum]['sum'] += $count;
                        ++$primary[$stratum][$candidate]['count'];
                        $primary[$stratum][$candidate]['sum'] += $count;
                    }
                }
            }
            $day = $day->modify('-7 days');
        }

        $result = [];
        foreach ($strata as $stratum) {
            for ($hour = $hourFrom; $hour < $hourFrom + 6; ++$hour) {
                $result[$stratum][$hour] = [
                    $primary[$stratum][$hour]['count'],
                    $primary[$stratum][$hour]['sum'],
                    $fallback[$stratum]['count'],
                    $fallback[$stratum]['sum'],
                ];
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
        if ($hourStart < $refStart || $hourEnd > $cutoff) {
            return null;
        }
        if (isset($blockedHours[$hourStart->format('Y-m-d H:00:00')])) {
            return null;
        }

        return $hourlyCounts[$hourStart->format('Y-m-d H:00:00')] ?? 0;
    }
}
