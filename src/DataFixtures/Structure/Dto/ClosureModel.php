<?php

declare(strict_types=1);

namespace App\DataFixtures\Structure\Dto;

/**
 * How the closures of a hospital look (ADR 022): the profile sets how much is closed (share of
 * department-hours), this model sets how it is closed.
 *
 * - event sizes: how many of the offered departments one event closes, as ranges of a share,
 * - durations in minutes, with whole days that start at midnight,
 * - care level per event, weekday factors for the day of an event,
 * - field weights: departments of some specialities are closed more often than others.
 *
 * The closure planner derives the number of events from these, so they never need tuning to reach
 * a share.
 */
final readonly class ClosureModel
{
    /**
     * @param array<string, array{share: float, min: float, max: float}> $eventSizes   name → share of events and the range of offered departments it closes (a range below 1 department is one department)
     * @param array<string, array{share: float, min: int, max: int}>     $durations    name → share of events and minutes; a duration of 1,440 minutes starts at midnight
     * @param array<string, float>                                       $careLevels   care level → share of events
     * @param array<int, float>                                          $weekdays     ISO weekday (1 = Monday) → factor, mean about 1
     * @param array<string, float>                                       $fieldWeights speciality key → weight of its departments; missing keys weigh 1
     * @param float                                                      $groupShare   share of multi-department events with an IVENA group id; the others share exact times
     */
    public function __construct(
        public array $eventSizes,
        public array $durations,
        public array $careLevels,
        public array $weekdays,
        public array $fieldWeights,
        public float $groupShare,
    ) {
    }

    /**
     * The number of departments an event of a size closes, as an inclusive range for N offered departments.
     *
     * @return array{int, int}
     */
    public function departmentRange(string $size, int $offered): array
    {
        $range = $this->eventSizes[$size];
        if ($offered <= 1) {
            return [1, 1];
        }
        $low = max(1, min($offered, (int) ceil($range['min'] * (float) $offered)));
        $high = max($low, min($offered, (int) floor($range['max'] * (float) $offered)));

        return [$low, $high];
    }

    /**
     * Expected departments one event closes, for N offered departments.
     */
    public function expectedDepartments(int $offered): float
    {
        $expected = 0.0;
        foreach ($this->eventSizes as $size => $range) {
            [$low, $high] = $this->departmentRange($size, $offered);
            $expected += $range['share'] * (float) ($low + $high) / 2.0;
        }

        return $expected;
    }

    /**
     * Expected hours one closure lasts (before it is cut at the end of the period).
     */
    public function expectedHours(): float
    {
        $minutes = 0.0;
        foreach ($this->durations as $duration) {
            $minutes += $duration['share'] * (float) ($duration['min'] + $duration['max']) / 2.0;
        }

        return $minutes / 60.0;
    }
}
