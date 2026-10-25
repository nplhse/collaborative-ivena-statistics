<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

/**
 * Plans hourly projection rows for one event, population and metric.
 * Counts and reference rates are supplied by the caller.
 */
final readonly class ClosureVolumeHourPlanner
{
    /**
     * Count series of a population and metric. Hospital counts double as the hospital-scope area.
     */
    public static function series(string $scope, int $specialityId, int $departmentId, ClosureVolumeMetricKind $metric): string
    {
        return match ($scope) {
            'speciality' => 's|'.$specialityId.'|'.$metric->value,
            'hospital' => 'h|'.$metric->value,
            default => 'd|'.$departmentId.'|'.$metric->value,
        };
    }

    public static function hospitalSeries(ClosureVolumeMetricKind $metric): string
    {
        return 'h|'.$metric->value;
    }

    /**
     * @param array<string, int|array<string, int>> $counts        "{series}" => hour => count, or flat partial keys "{series}|{start}|{end}"
     * @param array<string, ClosureVolumeRate>      $areaRates     key "{weekday}|{hour}"
     * @param array<string, ClosureVolumeRate>      $hospitalRates key "{weekday}|{hour}"
     * @param array<string, true>                   $coveredDays
     *
     * @return list<ClosureVolumeHourDraft>
     */
    public function plan(
        ClosureVolumeAnchor $anchor,
        \DateTimeImmutable $now,
        array $counts,
        array $areaRates,
        array $hospitalRates,
        array $coveredDays,
        ClosureVolumeNeighbourIndex $neighbours,
        ?ClosureVolumeProvisionalReason $provisional,
    ): array {
        $now = ClosureVolumeClock::wall($now);
        $areaSeries = self::series($anchor->scope, $anchor->specialityId, $anchor->departmentId, $anchor->metric);
        $hospitalSeries = self::hospitalSeries($anchor->metric);
        $context = sprintf('%d hours', ClosureVolumeReferenceConfig::CONTEXT_HOURS);
        $influencing = $neighbours->influencing($anchor, $anchor->startsAt->modify('-'.$context), $anchor->endsAt->modify('+'.$context));
        $drafts = [];
        foreach ($this->ranges($anchor, $now) as [$inClosure, $buckets]) {
            foreach ($buckets as $bucket) {
                $rateKey = $bucket->weekday.'|'.$bucket->hour;
                $areaRate = $areaRates[$rateKey] ?? $this->missingRate();
                $hospitalRate = $hospitalRates[$rateKey] ?? $this->missingRate();
                $covered = isset($coveredDays[$bucket->start->format('Y-m-d')]);
                $hourStart = $bucket->start->format('Y-m-d H:i:s');
                $fullHour = '00:00' === $bucket->start->format('i:s')
                    && $bucket->end->getTimestamp() === $bucket->start->getTimestamp() + 3600;
                $bucketKey = $hourStart.'|'.$bucket->end->format('Y-m-d H:i:s');

                $drafts[] = new ClosureVolumeHourDraft(
                    $anchor->eventId,
                    $anchor->hospitalId,
                    $anchor->scope,
                    $anchor->specialityId,
                    $anchor->departmentId,
                    $anchor->metric,
                    $bucket->start,
                    $bucket->end,
                    $inClosure,
                    $this->observed($covered, $counts, $areaSeries, $bucketKey, $fullHour, $hourStart),
                    $areaRate->expectedForSeconds($bucket->seconds),
                    $this->observed($covered, $counts, $hospitalSeries, $bucketKey, $fullHour, $hourStart),
                    $hospitalRate->expectedForSeconds($bucket->seconds),
                    $covered ? $bucket->seconds : 0,
                    $bucket->seconds,
                    $areaRate->slotCount,
                    $areaRate->blockedSlotCount,
                    $areaRate->assignmentCount,
                    $areaRate->mode->value,
                    $this->influenced($bucket, $inClosure, $influencing),
                    $this->quality($covered, $areaRate->mode)->value,
                    $anchor->relevance,
                    $anchor->closedDepartmentCount,
                    $provisional instanceof ClosureVolumeProvisionalReason,
                    $provisional,
                    $anchor->startsAt,
                );
            }
        }

        return $drafts;
    }

    /**
     * Context before the first segment, the segments, gaps between them and context after the last one.
     *
     * @return list<array{0: bool, 1: list<ClosureVolumeBucket>}>
     */
    public function ranges(ClosureVolumeAnchor $anchor, \DateTimeImmutable $now): array
    {
        $context = sprintf('%d hours', ClosureVolumeReferenceConfig::CONTEXT_HOURS);
        if ($anchor->startsAt >= $now && $anchor->startsAt->modify('-'.$context) >= $now) {
            return [];
        }

        $ranges = [];
        $preStart = $anchor->startsAt->modify('-'.$context);
        $preEnd = $anchor->startsAt < $now ? $anchor->startsAt : $now;
        if ($preStart < $preEnd) {
            $ranges[] = [false, ClosureVolumeClock::split($preStart, $preEnd)];
        }

        $previousEnd = null;
        foreach ($anchor->segments as [$segmentStart, $segmentEnd]) {
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

        if ($anchor->endsAt <= $now) {
            $postEnd = $anchor->endsAt->modify('+'.$context);
            if ($postEnd > $now) {
                $postEnd = $now;
            }
            if ($anchor->endsAt < $postEnd) {
                $ranges[] = [false, ClosureVolumeClock::split($anchor->endsAt, $postEnd)];
            }
        }

        return $ranges;
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
        string $series,
        string $bucketKey,
        bool $fullHour,
        string $hourStart,
    ): ?int {
        if (!$covered) {
            return null;
        }
        $flatKey = $series.'|'.$bucketKey;
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
     * A context hour is influenced when another event closes the same metric in the same population
     * ({@see ClosureVolumeNeighbourIndex::influencing()} already selected those closures).
     *
     * @param list<ClosureVolumeInterval> $influencing
     */
    private function influenced(ClosureVolumeBucket $bucket, bool $inClosure, array $influencing): bool
    {
        if ($inClosure) {
            return false;
        }

        return array_any($influencing, fn (ClosureVolumeInterval $neighbour): bool => $neighbour->startsAt < $bucket->end && $neighbour->endsAt > $bucket->start);
    }

    private function missingRate(): ClosureVolumeRate
    {
        return new ClosureVolumeRate(null, ClosureVolumeReferenceMode::Insufficient, 0, 0);
    }
}
