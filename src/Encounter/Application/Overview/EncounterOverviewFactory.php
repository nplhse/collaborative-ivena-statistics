<?php

declare(strict_types=1);

namespace App\Encounter\Application\Overview;

use App\Encounter\Application\Contract\EncounterOverviewReaderInterface;
use App\Encounter\Domain\Enum\ArrivalRoute;
use App\Encounter\Domain\Enum\CareLevel;
use App\Encounter\Domain\Enum\Disposition;
use App\Encounter\Domain\Enum\MatchStatus;
use App\Encounter\Domain\Enum\MtsCategory;

/**
 * Builds the descriptive overview of one hospital (#1055) from the raw counts of the reader and
 * applies small-cell suppression (ADR 012). Access is checked by the caller (VIEW_AGGREGATES).
 */
final readonly class EncounterOverviewFactory
{
    /** Number of MTS diagrams listed by name; the rest is summed up as "other diagrams". */
    public const int TOP_DIAGRAMS = 10;

    public const string NOT_DOCUMENTED = '';

    public const string OTHER = 'other';

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private EncounterOverviewReaderInterface $reader,
    ) {
    }

    /**
     * @param \Closure(array<string, int>): list<string>|null $timeAxis bucket keys to draw (`YYYY-MM-DD` or
     *                                                                  `YYYY-MM`) from the visits per key; the statistics page passes
     *                                                                  its axis rules (zero buckets, no incomplete month). Without it:
     *                                                                  every bucket from the first to the last visit.
     */
    public function create(int $hospitalId, EncounterPeriod $period, TimeGrain $grain = TimeGrain::Month, ?\Closure $timeAxis = null): EncounterOverview
    {
        $coverage = $this->reader->coverage($hospitalId, $period);
        $allTime = $period->isAllTime() ? $coverage : $this->reader->coverage($hospitalId, EncounterPeriod::allTime());
        $visits = $coverage->visits;

        [$diagrams, $diagramsShown] = $this->diagrams($this->reader->visitsByDiagram($hospitalId, $period), $visits);
        $careLevels = $this->reader->visitsByCareLevel($hospitalId, $period);
        $careLevelDenominator = array_sum($careLevels);

        $processTimes = [];
        $processBoxes = [];
        foreach (ProcessInterval::cases() as $interval) {
            $processTimes[] = new ProcessTimeRow($this->reader->processTime($hospitalId, $period, $interval));
            $processBoxes[$interval->value] = $this->boxes($this->reader->processTimeByCategory($hospitalId, $period, $interval));
        }

        $routeLinkCounts = $this->reader->visitsByRouteAndLink($hospitalId, $period);
        $routes = [];
        $links = [MatchStatus::AutoAccepted->value => 0, MatchStatus::Confirmed->value => 0, EncounterOverviewReaderInterface::NOT_LINKED => 0];
        foreach (ArrivalRoute::cases() as $route) {
            $byLink = $routeLinkCounts[$route->value] ?? [];
            $routes[] = new OverviewCount($route->value, array_sum($byLink), $visits);
            foreach ($byLink as $link => $count) {
                $links[$link] = ($links[$link] ?? 0) + $count;
            }
        }

        return new EncounterOverview(
            period: $period,
            coverage: $coverage,
            allTimeCoverage: $allTime,
            routeLink: $this->routeLink($routeLinkCounts, $this->reader->bonusDecidedLinksByRoute($hospitalId, $period)),
            categories: $this->ordered($this->reader->visitsByCategory($hospitalId, $period), array_map(static fn (MtsCategory $category): string => $category->value, MtsCategory::cases()), $visits),
            diagrams: $diagrams,
            diagramsShown: $diagramsShown,
            dispositions: $this->ordered($this->reader->visitsByDisposition($hospitalId, $period), array_map(static fn (Disposition $disposition): string => $disposition->value, Disposition::cases()), $visits),
            careLevels: $this->ordered($careLevels, array_map(static fn (CareLevel $level): string => $level->value, CareLevel::cases()), $careLevelDenominator),
            careLevelDenominator: $careLevelDenominator,
            processTimes: $processTimes,
            grain: $grain,
            timeSeries: 0 === $visits ? [] : $this->timeSeries($this->reader->visitsByBucket($hospitalId, $period, $grain), $grain, $timeAxis),
            processBoxes: $processBoxes,
            routes: $routes,
            links: [
                new OverviewCount('automatic', $links[MatchStatus::AutoAccepted->value], $visits),
                new OverviewCount('confirmed', $links[MatchStatus::Confirmed->value], $visits),
                new OverviewCount('not_linked', $links[EncounterOverviewReaderInterface::NOT_LINKED], $visits),
            ],
        );
    }

    /**
     * Buckets of the axis with visits per arrival route; buckets without visits as 0.
     *
     * @param array<string, array<string, int>>               $counts   bucket start `Y-m-d` => route => visits
     * @param \Closure(array<string, int>): list<string>|null $timeAxis
     *
     * @return list<TimeBucket>
     */
    private function timeSeries(array $counts, TimeGrain $grain, ?\Closure $timeAxis): array
    {
        $byKey = [];
        foreach ($counts as $start => $routes) {
            $byKey[$grain->key(new \DateTimeImmutable($start))] = $routes;
        }

        $totals = [];
        foreach ($byKey as $key => $routes) {
            $totals[$key] = (int) array_sum($routes);
        }
        $keys = $timeAxis instanceof \Closure ? $timeAxis($totals) : $this->observedAxis(array_keys($totals), $grain);

        $routes = array_map(static fn (ArrivalRoute $route): string => $route->value, ArrivalRoute::cases());
        $series = [];
        foreach ($keys as $key) {
            $start = new \DateTimeImmutable(TimeGrain::Day === $grain ? $key : $key.'-01');
            $series[] = new TimeBucket($start, $byKey[$key] ?? [], $routes);
        }

        return $series;
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private function observedAxis(array $keys, TimeGrain $grain): array
    {
        if ([] === $keys) {
            return [];
        }

        sort($keys);
        $step = TimeGrain::Day === $grain ? '+1 day' : '+1 month';
        $bucket = new \DateTimeImmutable(TimeGrain::Day === $grain ? $keys[0] : $keys[0].'-01');
        $last = end($keys);

        $axis = [];
        while ($grain->key($bucket) <= $last) {
            $axis[] = $grain->key($bucket);
            $bucket = $bucket->modify($step);
        }

        return $axis;
    }

    /**
     * @param list<ProcessTimeDistribution> $distributions
     *
     * @return list<ProcessTimeBox>
     */
    private function boxes(array $distributions): array
    {
        $byCategory = [];
        foreach ($distributions as $distribution) {
            $byCategory[$distribution->category] = $distribution;
        }

        $boxes = [];
        foreach ([...array_map(static fn (MtsCategory $category): string => $category->value, MtsCategory::cases()), self::NOT_DOCUMENTED] as $category) {
            if (isset($byCategory[$category])) {
                $boxes[] = new ProcessTimeBox($category, $byCategory[$category]);
            }
        }

        return $boxes;
    }

    /**
     * @param array<string, array<string, int>> $counts
     * @param array<string, int>                $bonusDecided route => automatic links decided by the plausibility bonus
     *
     * @return list<RouteLinkRow>
     */
    private function routeLink(array $counts, array $bonusDecided): array
    {
        $rows = [];
        $sum = [0, 0, 0, 0];
        foreach (ArrivalRoute::cases() as $route) {
            $byLink = $counts[$route->value] ?? [];
            $automatic = $byLink[MatchStatus::AutoAccepted->value] ?? 0;
            $confirmed = $byLink[MatchStatus::Confirmed->value] ?? 0;
            $notLinked = $byLink[EncounterOverviewReaderInterface::NOT_LINKED] ?? 0;
            $decided = $bonusDecided[$route->value] ?? 0;
            $rows[] = new RouteLinkRow($route->value, $automatic, $confirmed, $notLinked, $decided);
            $sum = [$sum[0] + $automatic, $sum[1] + $confirmed, $sum[2] + $notLinked, $sum[3] + $decided];
        }

        $rows[] = new RouteLinkRow('total', $sum[0], $sum[1], $sum[2], $sum[3]);

        return $rows;
    }

    /**
     * Every listed code in the given order (also with 0 visits), then codes not in the list (none
     * expected, enums are checked by the database), then "not documented".
     *
     * @param array<string, int> $counts
     * @param list<string>       $order
     *
     * @return list<OverviewCount>
     */
    private function ordered(array $counts, array $order, int $denominator): array
    {
        $rows = [];
        foreach ($order as $code) {
            $rows[] = new OverviewCount($code, $counts[$code] ?? 0, $denominator);
            unset($counts[$code]);
        }

        $missing = $counts[self::NOT_DOCUMENTED] ?? 0;
        unset($counts[self::NOT_DOCUMENTED]);
        foreach ($counts as $code => $count) {
            $rows[] = new OverviewCount((string) $code, $count, $denominator);
        }

        $rows[] = new OverviewCount(self::NOT_DOCUMENTED, $missing, $denominator);

        return $rows;
    }

    /**
     * Top diagrams by visits (ties by code), "other diagrams" and "not documented".
     *
     * @param array<string, int> $counts
     *
     * @return array{list<OverviewCount>, int}
     */
    private function diagrams(array $counts, int $visits): array
    {
        $missing = $counts[self::NOT_DOCUMENTED] ?? 0;
        unset($counts[self::NOT_DOCUMENTED]);

        $codes = array_map(strval(...), array_keys($counts));
        usort($codes, static fn (string $a, string $b): int => [$counts[$b], $a] <=> [$counts[$a], $b]);

        $rows = [];
        $other = 0;
        foreach ($codes as $index => $code) {
            if ($index < self::TOP_DIAGRAMS) {
                $rows[] = new OverviewCount($code, $counts[$code], $visits);
            } else {
                $other += $counts[$code];
            }
        }

        $shown = \count($rows);
        $rows[] = new OverviewCount(self::OTHER, $other, $visits);
        $rows[] = new OverviewCount(self::NOT_DOCUMENTED, $missing, $visits);

        return [$rows, $shown];
    }
}
