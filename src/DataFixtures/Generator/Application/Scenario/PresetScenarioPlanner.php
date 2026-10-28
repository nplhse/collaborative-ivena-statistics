<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Scenario;

use App\Allocation\Domain\Entity\Hospital;
use App\DataFixtures\FixtureVolume;
use App\DataFixtures\Generator\Infrastructure\Random\RandomStreams;
use Random\Randomizer;

/**
 * Places the scenarios of a preset (`scenarios` in `fixtures.profiles`) with the seed (ADR 022).
 *
 * The period is split into as many parts as the scenario is applied; each application starts on a
 * day of its part, a flu wave or icy road in winter and heat in summer if the part has such days.
 * - an MCI takes 2–4 hospitals: those of one dispatch area with at least two active hospitals, then the
 *   nearest active hospitals of other areas;
 * - a flu wave takes every active hospital;
 * - other scenarios take the active hospitals of one dispatch area.
 *
 * Same seed and hospitals give the same placement.
 */
final readonly class PresetScenarioPlanner
{
    /** Months a seasonal scenario prefers. */
    private const array SEASONS = [
        'flu-wave' => [12, 1, 2],
        'icy-road' => [12, 1, 2],
        'heat' => [6, 7, 8],
    ];

    private const string ALL_HOSPITALS = 'flu-wave';

    public function __construct(
        private RandomStreams $streams,
    ) {
    }

    /**
     * @param list<Hospital> $hospitals active hospitals of the preset
     *
     * @return list<PresetScenario> in start order
     */
    public function plan(FixtureVolume $volume, array $hospitals, \DateTimeImmutable $firstDay, \DateTimeImmutable $lastDay): array
    {
        if ([] === $hospitals) {
            return [];
        }
        usort($hospitals, static fn (Hospital $a, Hospital $b): int => strcmp($a->getPublicIdString(), $b->getPublicIdString()));

        $days = $this->days($firstDay, $lastDay);
        $placed = [];
        foreach (array_keys($volume->scenarios) as $name) {
            $definition = ScenarioCatalog::get($name);
            $count = $volume->scenarioCount($name);
            for ($i = 0; $i < $count; ++$i) {
                $area = sprintf('preset:%s:%d', $name, $i);
                $random = $this->streams->stream($volume->seed, 'preset-scenarios', $area, 'placement');
                $part = \array_slice($days, intdiv($i * \count($days), $count), max(1, intdiv(\count($days), $count)));
                $placed[] = new PresetScenario(
                    $definition,
                    $this->startDay($name, $part, $random),
                    $this->hospitalIds($definition, $hospitals, $random),
                    $area,
                );
            }
        }
        usort($placed, static fn (PresetScenario $a, PresetScenario $b): int => [$a->startDay, $a->definition->name] <=> [$b->startDay, $b->definition->name]);

        return $placed;
    }

    /**
     * @param list<\DateTimeImmutable> $part
     */
    private function startDay(string $name, array $part, Randomizer $random): \DateTimeImmutable
    {
        $season = self::SEASONS[$name] ?? [];
        $candidates = array_values(array_filter($part, static fn (\DateTimeImmutable $day): bool => \in_array((int) $day->format('n'), $season, true)));
        if ([] === $candidates) {
            $candidates = $part;
        }

        return $candidates[$random->getInt(0, \count($candidates) - 1)];
    }

    /**
     * @param list<Hospital> $hospitals sorted by public id
     *
     * @return list<int>
     */
    private function hospitalIds(ScenarioDefinition $definition, array $hospitals, Randomizer $random): array
    {
        if (self::ALL_HOSPITALS === $definition->name) {
            return $this->ids($hospitals);
        }

        $byArea = [];
        foreach ($hospitals as $hospital) {
            $byArea[(string) $hospital->getDispatchArea()?->getName()][] = $hospital;
        }
        ksort($byArea);
        $areas = array_keys($byArea);
        if ($definition->isMci()) {
            $withTwo = array_values(array_filter($areas, static fn (int|string $area): bool => \count($byArea[$area]) >= 2));
            $areas = [] !== $withTwo ? $withTwo : $areas;
        }
        $area = $areas[$random->getInt(0, \count($areas) - 1)];
        if (!$definition->isMci()) {
            return $this->ids($byArea[$area]);
        }

        // MCI: the area first, then the nearest hospitals of other areas, 2–4 in all.
        $wanted = min(\count($hospitals), $random->getInt(2, 4));
        $inArea = \array_slice($byArea[$area], 0, $wanted);
        $others = array_values(array_filter($hospitals, static fn (Hospital $hospital): bool => !\in_array($hospital, $byArea[$area], true)));
        $center = $this->center($inArea);
        usort($others, fn (Hospital $a, Hospital $b): int => [$this->distance($a, $center), $a->getPublicIdString()] <=> [$this->distance($b, $center), $b->getPublicIdString()]);

        return $this->ids(array_merge($inArea, \array_slice($others, 0, $wanted - \count($inArea))));
    }

    /**
     * @param list<Hospital> $hospitals
     *
     * @return array{float, float}|null
     */
    private function center(array $hospitals): ?array
    {
        $latitude = 0.0;
        $longitude = 0.0;
        $count = 0;
        foreach ($hospitals as $hospital) {
            $lat = $hospital->getLatitude();
            $lon = $hospital->getLongitude();
            if (null !== $lat && null !== $lon) {
                $latitude += $lat;
                $longitude += $lon;
                ++$count;
            }
        }

        return $count > 0 ? [$latitude / (float) $count, $longitude / (float) $count] : null;
    }

    /**
     * Rough distance in degrees; enough to order hospitals. Without coordinates a hospital comes last.
     *
     * @param array{float, float}|null $center
     */
    private function distance(Hospital $hospital, ?array $center): float
    {
        if (null === $center) {
            return \PHP_FLOAT_MAX;
        }
        $latitude = $hospital->getLatitude();
        $longitude = $hospital->getLongitude();
        if (null === $latitude || null === $longitude) {
            return \PHP_FLOAT_MAX;
        }

        return hypot($latitude - $center[0], ($longitude - $center[1]) * 0.66);
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    private function days(\DateTimeImmutable $firstDay, \DateTimeImmutable $lastDay): array
    {
        $days = [];
        for ($day = $firstDay->setTime(0, 0); $day <= $lastDay; $day = $day->modify('+1 day')) {
            $days[] = $day;
        }

        return $days;
    }

    /**
     * @param list<Hospital> $hospitals
     *
     * @return list<int>
     */
    private function ids(array $hospitals): array
    {
        return array_map(static fn (Hospital $hospital): int => (int) $hospital->getId(), $hospitals);
    }
}
