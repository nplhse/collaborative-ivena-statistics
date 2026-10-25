<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

/**
 * Closures of a partition window grouped by the population and metric they close, sorted by start.
 * The planner asks once per anchor for the closures of other events that can influence its context
 * hours, instead of scanning every closure of the window for every hour row.
 */
final class ClosureVolumeNeighbourIndex
{
    /** @var array<string, list<ClosureVolumeInterval>> */
    private array $byPopulation = [];

    /**
     * @param list<ClosureVolumeInterval> $intervals
     */
    public static function fromIntervals(array $intervals): self
    {
        $index = new self();
        foreach ($intervals as $interval) {
            foreach (ClosureVolumeMetricKind::all() as $metric) {
                if (!self::closes($interval, $metric)) {
                    continue;
                }
                $index->byPopulation['d|'.$interval->departmentId.'|'.$metric->value][] = $interval;
                $index->byPopulation['s|'.$interval->specialityId.'|'.$metric->value][] = $interval;
                $index->byPopulation['h|'.$metric->value][] = $interval;
            }
        }
        foreach ($index->byPopulation as &$list) {
            usort($list, static fn (ClosureVolumeInterval $left, ClosureVolumeInterval $right): int => $left->startsAt <=> $right->startsAt);
        }
        unset($list);

        return $index;
    }

    /**
     * Closures of other events that close the anchor's metric in its population and overlap [from, to).
     *
     * @return list<ClosureVolumeInterval>
     */
    public function influencing(ClosureVolumeAnchor $anchor, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $key = match ($anchor->scope) {
            'speciality' => 's|'.$anchor->specialityId.'|'.$anchor->metric->value,
            'hospital' => 'h|'.$anchor->metric->value,
            default => 'd|'.$anchor->departmentId.'|'.$anchor->metric->value,
        };
        $result = [];
        foreach ($this->byPopulation[$key] ?? [] as $interval) {
            if ($interval->startsAt >= $to) {
                break;
            }
            if ($interval->endsAt > $from && $interval->eventId !== $anchor->eventId) {
                $result[] = $interval;
            }
        }

        return $result;
    }

    /**
     * Resource metrics count as closed only by an interval that opens SK1–SK3.
     */
    private static function closes(ClosureVolumeInterval $interval, ClosureVolumeMetricKind $metric): bool
    {
        return array_all($metric->bindingUrgencies(), fn (int $urgency): bool => $interval->coversUrgency($urgency));
    }
}
