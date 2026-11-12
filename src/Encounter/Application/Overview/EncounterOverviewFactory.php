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
        foreach (ProcessInterval::cases() as $interval) {
            $processTimes[] = new ProcessTimeRow($this->reader->processTime($hospitalId, $period, $interval));
        }

        return new EncounterOverview(
            period: $period,
            coverage: $coverage,
            allTimeCoverage: $allTime,
            routeLink: $this->routeLink($this->reader->visitsByRouteAndLink($hospitalId, $period)),
            categories: $this->ordered($this->reader->visitsByCategory($hospitalId, $period), array_map(static fn (MtsCategory $category): string => $category->value, MtsCategory::cases()), $visits),
            diagrams: $diagrams,
            diagramsShown: $diagramsShown,
            dispositions: $this->ordered($this->reader->visitsByDisposition($hospitalId, $period), array_map(static fn (Disposition $disposition): string => $disposition->value, Disposition::cases()), $visits),
            careLevels: $this->ordered($careLevels, array_map(static fn (CareLevel $level): string => $level->value, CareLevel::cases()), $careLevelDenominator),
            careLevelDenominator: $careLevelDenominator,
            processTimes: $processTimes,
        );
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
