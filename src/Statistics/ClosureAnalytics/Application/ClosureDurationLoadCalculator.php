<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application;

use App\Statistics\ClosureAnalytics\Application\DTO\ClosureDurationDistributionGroup;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureDurationInterval;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureDurationLoad;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureObservedSegment;
use App\Statistics\HospitalPopulation\Application\DescriptiveStatisticsCalculator;

/**
 * Duration, phase and department-concurrency metrics for one analysis context.
 *
 * Intervals are half-open. Touching intervals of the same hospital form one
 * hospital phase. Hospitals are never merged. A gap between those phases is
 * counted only when it lies inside the estimated import span. That span is not
 * confirmed export coverage, so the pause is a hospital-phase gap inside the
 * estimate. Department and speciality phases are a separate view: without a
 * known export period their gaps stay unconfirmed.
 */
final readonly class ClosureDurationLoadCalculator
{
    public const int TOP_LIMIT = 10;

    public function __construct(
        private ClosureDurationDistribution $distribution,
        private DescriptiveStatisticsCalculator $statistics,
    ) {
    }

    /**
     * @param list<ClosureDurationInterval> $intervals
     * @param list<ClosureObservedSegment>  $observed
     */
    public function calculate(array $intervals, array $observed): ClosureDurationLoad
    {
        $eventRanges = [];
        $specialityRanges = [];
        $reasonRanges = [];
        $phaseRanges = [];
        $specialityMeta = [];
        $reasonMeta = [];
        $activeDepartmentRanges = [];

        foreach ($intervals as $interval) {
            $start = $interval->start->getTimestamp();
            $end = $interval->end->getTimestamp();
            if ($end <= $start) {
                continue;
            }

            $range = [$start, $end];
            $eventRanges[$interval->eventKey][] = $range;
            $phaseRanges[$interval->hospitalId][] = $range;
            $specialityKey = $interval->eventKey."\0".$interval->specialityId;
            $specialityRanges[$specialityKey][] = $range;
            $specialityMeta[$specialityKey] = [
                'id' => $interval->specialityId,
                'name' => $interval->specialityName,
            ];
            $reasonKey = $interval->reason ?? '';
            $reasonCompound = $interval->eventKey."\0".$reasonKey;
            $reasonRanges[$reasonCompound][] = $range;
            $reasonMeta[$reasonCompound] = $reasonKey;
            $activeDepartmentRanges[$interval->hospitalId][$interval->departmentId][] = $range;
        }

        $eventDurations = [];
        foreach ($eventRanges as $ranges) {
            $eventDurations[] = $this->duration($this->merge($ranges));
        }

        $specialityDurations = [];
        foreach ($specialityRanges as $compound => $ranges) {
            $meta = $specialityMeta[$compound];
            $id = $meta['id'];
            $specialityDurations[$id]['name'] = $this->preferredLabel(
                $specialityDurations[$id]['name'] ?? null,
                $meta['name'],
            );
            $specialityDurations[$id]['durations'][] = $this->duration($this->merge($ranges));
        }

        $reasonDurations = [];
        foreach ($reasonRanges as $compound => $ranges) {
            $key = $reasonMeta[$compound];
            $reasonDurations[$key]['durations'][] = $this->duration($this->merge($ranges));
        }

        $phases = [];
        $pauses = [];
        $longestPhase = null;
        $observedByHospital = $this->observedByHospital($observed);
        foreach ($phaseRanges as $hospitalId => $ranges) {
            $merged = $this->merge($ranges);
            $phases[$hospitalId] = $merged;
            $previousEnd = null;
            foreach ($merged as [$start, $end]) {
                $length = $end - $start;
                $longestPhase = null === $longestPhase ? $length : max($longestPhase, $length);
                if (null !== $previousEnd && $this->gapFullyObserved($observedByHospital[$hospitalId] ?? [], $previousEnd, $start)) {
                    $pauses[] = $start - $previousEnd;
                }
                $previousEnd = $end;
            }
        }

        $shares = $this->shares($observedByHospital, $activeDepartmentRanges);
        $eventStats = [] === $eventDurations ? null : $this->statistics->calculate($eventDurations);
        $pauseStats = [] === $pauses ? null : $this->statistics->calculate($pauses);
        [$specialities, $specialitiesTruncated] = $this->topGroups(
            $specialityDurations,
            static fn (int|string $id, string $name): array => [(string) $id, $name],
        );
        [$reasons, $reasonsTruncated] = $this->topGroups(
            $reasonDurations,
            static fn (int|string $key, string $name): array => [(string) $key, $name],
        );

        return new ClosureDurationLoad(
            \count($eventDurations),
            $eventStats?->median,
            $eventStats?->mean,
            $eventStats?->minimum,
            $eventStats?->maximum,
            $longestPhase,
            array_sum(array_map(\count(...), $phases)),
            $pauseStats instanceof \App\Statistics\HospitalPopulation\Application\DTO\DescriptiveStats,
            $pauseStats?->median,
            $pauseStats?->minimum,
            $pauseStats?->maximum,
            $shares['evaluable'],
            $shares['none'],
            $shares['single'],
            $shares['multiple'],
            $specialities,
            $specialitiesTruncated,
            $reasons,
            $reasonsTruncated,
        );
    }

    /**
     * @param array<int|string, array{name?: string, durations: list<int>}> $buckets
     * @param callable(int|string, string): array{0: string, 1: string}     $identity
     *
     * @return array{0: list<ClosureDurationDistributionGroup>, 1: bool}
     */
    private function topGroups(array $buckets, callable $identity): array
    {
        $rows = [];
        foreach ($buckets as $id => $bucket) {
            $durations = $bucket['durations'];
            if ([] === $durations) {
                continue;
            }
            [$key, $label] = $identity($id, $bucket['name'] ?? (string) $id);
            $rows[] = [
                'key' => $key,
                'label' => $label,
                'count' => \count($durations),
                'durations' => $durations,
            ];
        }

        usort($rows, static fn (array $left, array $right): int => $right['count'] <=> $left['count']
            ?: strcmp($left['label'], $right['label'])
            ?: strcmp($left['key'], $right['key']));
        $truncated = \count($rows) > self::TOP_LIMIT;
        $selected = \array_slice($rows, 0, self::TOP_LIMIT);
        $groups = array_map(
            fn (array $row): ClosureDurationDistributionGroup => $this->distribution->summarize($row['key'], $row['label'], $row['durations']),
            $selected,
        );
        usort($groups, static fn (ClosureDurationDistributionGroup $left, ClosureDurationDistributionGroup $right): int => $right->medianSeconds <=> $left->medianSeconds
            ?: strcmp($left->label, $right->label)
            ?: strcmp($left->key, $right->key));

        return [$groups, $truncated];
    }

    /**
     * @param list<ClosureObservedSegment> $observed
     *
     * @return array<int, list<array{0: int, 1: int}>>
     */
    private function observedByHospital(array $observed): array
    {
        $ranges = [];
        foreach ($observed as $segment) {
            $start = $segment->start->getTimestamp();
            $end = $segment->end->getTimestamp();
            if ($end <= $start) {
                continue;
            }
            $ranges[$segment->hospitalId][] = [$start, $end];
        }

        $merged = [];
        foreach ($ranges as $hospitalId => $hospitalRanges) {
            $merged[$hospitalId] = $this->merge($hospitalRanges);
        }

        return $merged;
    }

    /**
     * @param array<int, list<array{0: int, 1: int}>>             $observedByHospital
     * @param array<int, array<int, list<array{0: int, 1: int}>>> $activeDepartmentRanges
     *
     * @return array{evaluable: int, none: int, single: int, multiple: int}
     */
    private function shares(array $observedByHospital, array $activeDepartmentRanges): array
    {
        $totals = ['evaluable' => 0, 'none' => 0, 'single' => 0, 'multiple' => 0];
        $hospitalIds = array_unique([
            ...array_keys($observedByHospital),
            ...array_keys($activeDepartmentRanges),
        ]);
        foreach ($hospitalIds as $hospitalId) {
            $points = [];
            foreach ($observedByHospital[$hospitalId] ?? [] as [$start, $end]) {
                $points[$start]['observed'] = ($points[$start]['observed'] ?? 0) + 1;
                $points[$end]['observed'] = ($points[$end]['observed'] ?? 0) - 1;
            }
            foreach ($activeDepartmentRanges[$hospitalId] ?? [] as $ranges) {
                foreach ($this->merge($ranges) as [$start, $end]) {
                    $points[$start]['departments'] = ($points[$start]['departments'] ?? 0) + 1;
                    $points[$end]['departments'] = ($points[$end]['departments'] ?? 0) - 1;
                }
            }
            if ([] === $points) {
                continue;
            }
            ksort($points, SORT_NUMERIC);
            $previous = null;
            $observed = 0;
            $departments = 0;
            foreach ($points as $instant => $delta) {
                if (null !== $previous && $instant > $previous && $observed > 0) {
                    $length = $instant - $previous;
                    $totals['evaluable'] += $length;
                    if ($departments <= 0) {
                        $totals['none'] += $length;
                    } elseif (1 === $departments) {
                        $totals['single'] += $length;
                    } else {
                        $totals['multiple'] += $length;
                    }
                }
                $observed += $delta['observed'] ?? 0;
                $departments += $delta['departments'] ?? 0;
                $previous = $instant;
            }
        }

        return $totals;
    }

    /**
     * @param list<array{0: int, 1: int}> $observed
     */
    private function gapFullyObserved(array $observed, int $start, int $end): bool
    {
        if ($end <= $start) {
            return false;
        }

        $cursor = $start;
        foreach ($observed as [$segmentStart, $segmentEnd]) {
            if ($segmentEnd <= $cursor) {
                continue;
            }
            if ($segmentStart > $cursor) {
                return false;
            }
            $cursor = max($cursor, $segmentEnd);
            if ($cursor >= $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{0: int, 1: int}> $ranges
     *
     * @return list<array{0: int, 1: int}>
     */
    private function merge(array $ranges): array
    {
        if ([] === $ranges) {
            return [];
        }

        usort($ranges, static fn (array $left, array $right): int => $left[0] <=> $right[0] ?: $left[1] <=> $right[1]);
        $merged = [];
        $start = $ranges[0][0];
        $end = $ranges[0][1];
        $count = \count($ranges);
        for ($index = 1; $index < $count; ++$index) {
            if ($ranges[$index][0] <= $end) {
                $end = max($end, $ranges[$index][1]);
                continue;
            }
            $merged[] = [$start, $end];
            $start = $ranges[$index][0];
            $end = $ranges[$index][1];
        }
        $merged[] = [$start, $end];

        return $merged;
    }

    /**
     * @param list<array{0: int, 1: int}> $ranges
     */
    private function duration(array $ranges): int
    {
        $seconds = 0;
        foreach ($ranges as [$start, $end]) {
            $seconds += $end - $start;
        }

        return $seconds;
    }

    private function preferredLabel(?string $current, string $candidate): string
    {
        if (null === $current || '' === $current) {
            return $candidate;
        }
        if ('' === $candidate) {
            return $current;
        }

        return strcmp($candidate, $current) < 0 ? $candidate : $current;
    }
}
