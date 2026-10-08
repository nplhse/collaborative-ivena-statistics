<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

/**
 * Plans hourly projection rows for one closure. Counts and reference slots are supplied by the caller.
 */
final readonly class ClosureVolumeHourPlanner
{
    private const array STRATA = ['base', 'resus', 'cathlab'];

    /**
     * @param array<string, int|array<string, int>> $areaCounts     flat partial keys or "{series}" => hour => count
     * @param array<string, int|array<string, int>> $hospitalCounts flat partial keys or "{series}" => hour => count
     * @param array<string, ClosureVolumeRate>      $areaRates      key "{dept}|{urgency}|{stratum}|{weekday}|{hour}"
     * @param array<string, ClosureVolumeRate>      $hospitalRates  key "{urgency}|{stratum}|{weekday}|{hour}"
     * @param array<string, true>                   $coveredDays
     * @param list<ClosureVolumeInterval>           $neighbours
     *
     * @return list<ClosureVolumeHourDraft>
     */
    public function plan(
        ClosureVolumeInterval $closure,
        \DateTimeImmutable $now,
        array $areaCounts,
        array $hospitalCounts,
        array $areaRates,
        array $hospitalRates,
        array $coveredDays,
        array $neighbours,
    ): array {
        $now = ClosureVolumeClock::wall($now);
        $drafts = [];
        foreach ($this->ranges($closure, $now) as [$inClosure, $buckets]) {
            foreach ($closure->urgencies as $urgency) {
                foreach (self::STRATA as $stratum) {
                    $areaKey = $this->areaSeries($closure, $urgency, $stratum);
                    $hospitalKey = $urgency.'|'.$stratum;
                    foreach ($buckets as $bucket) {
                        $drafts[] = $this->draft(
                            $closure,
                            $bucket,
                            $inClosure,
                            $urgency,
                            $stratum,
                            $areaCounts,
                            $hospitalCounts,
                            $areaRates,
                            $hospitalRates,
                            $coveredDays,
                            $neighbours,
                            $areaKey,
                            $hospitalKey,
                        );
                    }
                }
            }
        }

        return $drafts;
    }

    /**
     * @return list<array{0: bool, 1: list<ClosureVolumeBucket>}>
     */
    public function ranges(ClosureVolumeInterval $closure, \DateTimeImmutable $now): array
    {
        $segments = [] === $closure->segments
            ? [[$closure->startsAt, $closure->endsAt]]
            : $closure->segments;
        if ($closure->startsAt >= $now && $closure->startsAt->modify(sprintf('-%d hours', ClosureVolumeReferenceConfig::CONTEXT_HOURS)) >= $now) {
            return [];
        }

        $ranges = [];
        $preStart = $closure->startsAt->modify(sprintf('-%d hours', ClosureVolumeReferenceConfig::CONTEXT_HOURS));
        $preEnd = $closure->startsAt < $now ? $closure->startsAt : $now;
        if ($preStart < $preEnd) {
            $ranges[] = [false, ClosureVolumeClock::split($preStart, $preEnd)];
        }

        $previousEnd = null;
        foreach ($segments as [$segmentStart, $segmentEnd]) {
            if (null !== $previousEnd && $previousEnd < $segmentStart) {
                $gapEnd = $segmentStart < $now ? $segmentStart : $now;
                if ($previousEnd < $gapEnd) {
                    $ranges[] = [false, ClosureVolumeClock::split($previousEnd, $gapEnd)];
                }
            }
            $duringEnd = $segmentEnd < $now ? $segmentEnd : $now;
            if ($segmentStart < $duringEnd) {
                $ranges[] = [true, ClosureVolumeClock::split($segmentStart, $duringEnd)];
            }
            $previousEnd = null === $previousEnd || $segmentEnd > $previousEnd ? $segmentEnd : $previousEnd;
        }

        if ($closure->endsAt <= $now) {
            $postEnd = $closure->endsAt->modify(sprintf('+%d hours', ClosureVolumeReferenceConfig::CONTEXT_HOURS));
            if ($postEnd > $now) {
                $postEnd = $now;
            }
            if ($closure->endsAt < $postEnd) {
                $ranges[] = [false, ClosureVolumeClock::split($closure->endsAt, $postEnd)];
            }
        }

        return $ranges;
    }

    /**
     * @param array<string, int|array<string, int>> $areaCounts
     * @param array<string, int|array<string, int>> $hospitalCounts
     * @param array<string, ClosureVolumeRate>      $areaRates
     * @param array<string, ClosureVolumeRate>      $hospitalRates
     * @param array<string, true>                   $coveredDays
     * @param list<ClosureVolumeInterval>           $neighbours
     */
    private function draft(
        ClosureVolumeInterval $closure,
        ClosureVolumeBucket $bucket,
        bool $inClosure,
        int $urgency,
        string $stratum,
        array $areaCounts,
        array $hospitalCounts,
        array $areaRates,
        array $hospitalRates,
        array $coveredDays,
        array $neighbours,
        string $areaKey,
        string $hospitalKey,
    ): ClosureVolumeHourDraft {
        $rateKey = $bucket->weekday.'|'.$bucket->hour;
        $areaRate = $areaRates[$areaKey.'|'.$rateKey] ?? $this->missingRate();
        $hospitalRate = $hospitalRates[$hospitalKey.'|'.$rateKey] ?? $this->missingRate();
        $covered = isset($coveredDays[$bucket->start->format('Y-m-d')]);
        $hourStart = $bucket->start->format('Y-m-d H:i:s');
        $fullHour = '00:00' === $bucket->start->format('i:s')
            && $bucket->end->getTimestamp() === $bucket->start->getTimestamp() + 3600;
        $bucketKey = $hourStart.'|'.$bucket->end->format('Y-m-d H:i:s');
        $observedArea = $this->observed($covered, $areaCounts, $areaKey.'|'.$bucketKey, $fullHour, $areaKey, $hourStart);
        $observedHospital = $this->observed($covered, $hospitalCounts, $hospitalKey.'|'.$bucketKey, $fullHour, $hospitalKey, $hourStart);
        $quality = $this->quality($covered, $areaRate->mode);

        return new ClosureVolumeHourDraft(
            $closure->eventId > 0 ? $closure->eventId : $closure->id,
            $closure->hospitalId,
            $closure->departmentId,
            $urgency,
            $stratum,
            $bucket->start,
            $bucket->end,
            $inClosure,
            $observedArea,
            $areaRate->expectedForSeconds($bucket->seconds),
            $observedHospital,
            $hospitalRate->expectedForSeconds($bucket->seconds),
            $covered ? $bucket->seconds : 0,
            $bucket->seconds,
            $areaRate->slotCount,
            $areaRate->assignmentCount,
            $areaRate->mode->value,
            $this->influenced($bucket, $inClosure, $closure, $urgency, $neighbours),
            $quality->value,
            $closure->startsAt,
            $closure->scope,
            $closure->specialityId,
        );
    }

    /**
     * A partial interval is counted from its own timestamp range.
     * A full clock hour may use the pre-aggregated hour only when the bucket is that hour.
     * Seeing one partial does not zero another partial of the same clock hour.
     *
     * @param array<string, int|array<string, int>> $counts
     */
    private function observed(
        bool $covered,
        array $counts,
        string $flatKey,
        bool $fullHour,
        string $series,
        string $hourStart,
    ): ?int {
        if (!$covered) {
            return null;
        }
        if (!$fullHour && isset($counts[$flatKey]) && \is_int($counts[$flatKey])) {
            return $counts[$flatKey];
        }
        if ($fullHour && isset($counts[$series][$hourStart])) {
            return $counts[$series][$hourStart];
        }
        if ($fullHour && isset($counts[$flatKey]) && \is_int($counts[$flatKey])) {
            return $counts[$flatKey];
        }

        return 0;
    }

    private function areaSeries(ClosureVolumeInterval $closure, int $urgency, string $stratum): string
    {
        return match ($closure->scope) {
            'speciality' => 's|'.$closure->specialityId.'|'.$urgency.'|'.$stratum,
            'hospital' => $urgency.'|'.$stratum,
            default => $closure->departmentId.'|'.$urgency.'|'.$stratum,
        };
    }

    private function quality(bool $covered, ClosureVolumeReferenceMode $mode): ClosureVolumeQuality
    {
        if (!$covered) {
            return ClosureVolumeQuality::Incomplete;
        }

        return match ($mode) {
            ClosureVolumeReferenceMode::Insufficient => ClosureVolumeQuality::Insufficient,
            ClosureVolumeReferenceMode::DayTimeBucket => ClosureVolumeQuality::Limited,
            ClosureVolumeReferenceMode::WeekdayHour => ClosureVolumeQuality::Reliable,
        };
    }

    /**
     * @param list<ClosureVolumeInterval> $neighbours
     */
    private function influenced(
        ClosureVolumeBucket $bucket,
        bool $inClosure,
        ClosureVolumeInterval $closure,
        int $urgency,
        array $neighbours,
    ): bool {
        if ($inClosure) {
            return false;
        }
        foreach ($neighbours as $neighbour) {
            if ($this->sameAnchor($closure, $neighbour) || !$this->samePopulation($closure, $neighbour)) {
                continue;
            }
            if (!$neighbour->coversUrgency($urgency)) {
                continue;
            }
            if ($neighbour->startsAt < $bucket->end && $neighbour->endsAt > $bucket->start) {
                return true;
            }
        }

        return false;
    }

    private function sameAnchor(ClosureVolumeInterval $closure, ClosureVolumeInterval $neighbour): bool
    {
        if ($closure->eventId > 0 && $neighbour->eventId > 0) {
            return $neighbour->eventId === $closure->eventId;
        }

        return $neighbour->id === $closure->id;
    }

    private function samePopulation(ClosureVolumeInterval $closure, ClosureVolumeInterval $neighbour): bool
    {
        return match ($closure->scope) {
            'speciality' => $neighbour->specialityId === $closure->specialityId,
            'hospital' => true,
            default => $neighbour->departmentId === $closure->departmentId,
        };
    }

    private function missingRate(): ClosureVolumeRate
    {
        return new ClosureVolumeRate(null, ClosureVolumeReferenceMode::Insufficient, 0, 0);
    }
}
