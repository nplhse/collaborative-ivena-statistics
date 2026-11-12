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

    public function create(int $hospitalId, EncounterPeriod $period): EncounterOverview
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

        $grain = TimeGrain::forSpan($coverage->firstAnchor, $coverage->lastAnchor);

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
            routeLink: $this->routeLink($routeLinkCounts),
            categories: $this->ordered($this->reader->visitsByCategory($hospitalId, $period), array_map(static fn (MtsCategory $category): string => $category->value, MtsCategory::cases()), $visits),
            diagrams: $diagrams,
            diagramsShown: $diagramsShown,
            dispositions: $this->ordered($this->reader->visitsByDisposition($hospitalId, $period), array_map(static fn (Disposition $disposition): string => $disposition->value, Disposition::cases()), $visits),
            careLevels: $this->ordered($careLevels, array_map(static fn (CareLevel $level): string => $level->value, CareLevel::cases()), $careLevelDenominator),
            careLevelDenominator: $careLevelDenominator,
            processTimes: $processTimes,
            grain: $grain,
            timeSeries: 0 === $visits ? [] : $this->timeSeries($this->reader->visitsByBucket($hospitalId, $period, $grain), $grain, $coverage),
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
     * Every bucket from the first to the last visit of the period, empty ones as 0.
     *
     * @param array<string, array<string, int>> $counts
     *
     * @return list<TimeBucket>
     */
    private function timeSeries(array $counts, TimeGrain $grain, EncounterCoverage $coverage): array
    {
        if (!$coverage->firstAnchor instanceof \DateTimeImmutable || !$coverage->lastAnchor instanceof \DateTimeImmutable) {
            return [];
        }

        $routes = array_map(static fn (ArrivalRoute $route): string => $route->value, ArrivalRoute::cases());
        $step = TimeGrain::Week === $grain ? '+1 week' : '+1 month';
        $bucket = $this->bucketStart($coverage->firstAnchor, $grain);
        $last = $this->bucketStart($coverage->lastAnchor, $grain);

        $series = [];
        while ($bucket <= $last) {
            $series[] = new TimeBucket($bucket, $counts[$bucket->format('Y-m-d')] ?? [], $routes);
            $bucket = $bucket->modify($step);
        }

        return $series;
    }

    private function bucketStart(\DateTimeImmutable $at, TimeGrain $grain): \DateTimeImmutable
    {
        $day = new \DateTimeImmutable($at->format('Y-m-d'));

        return TimeGrain::Week === $grain ? $day->modify('monday this week') : $day->modify('first day of this month');
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
     *
     * @return list<RouteLinkRow>
     */
    private function routeLink(array $counts): array
    {
        $rows = [];
        $sum = [0, 0, 0];
        foreach (ArrivalRoute::cases() as $route) {
            $byLink = $counts[$route->value] ?? [];
            $automatic = $byLink[MatchStatus::AutoAccepted->value] ?? 0;
            $confirmed = $byLink[MatchStatus::Confirmed->value] ?? 0;
            $notLinked = $byLink[EncounterOverviewReaderInterface::NOT_LINKED] ?? 0;
            $rows[] = new RouteLinkRow($route->value, $automatic, $confirmed, $notLinked);
            $sum = [$sum[0] + $automatic, $sum[1] + $confirmed, $sum[2] + $notLinked];
        }

        $rows[] = new RouteLinkRow('total', $sum[0], $sum[1], $sum[2]);

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
