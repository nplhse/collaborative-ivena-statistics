<?php

declare(strict_types=1);

namespace App\Encounter\Application\Overview;

/**
 * Descriptive overview of the encounter data of one hospital for one period (#1055). Each list
 * states its denominator; see docs/04-features/encounter/README.md#hospital-overview.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the overview templates.
 */
final readonly class EncounterOverview
{
    /**
     * @param list<RouteLinkRow>                  $routeLink    ems, self, unknown, total
     * @param list<OverviewCount>                 $categories   MTS categories red … blue, then '' (not documented); of all visits
     * @param list<OverviewCount>                 $diagrams     top diagrams, then 'other' and '' (not documented); of all visits
     * @param list<OverviewCount>                 $dispositions dispositions in code order, then ''; of all visits
     * @param list<OverviewCount>                 $careLevels   care levels, then ''; of admissions and external transfers
     * @param list<ProcessTimeRow>                $processTimes
     * @param list<TimeBucket>                    $timeSeries   buckets of the time axis, empty ones included
     * @param array<string, list<ProcessTimeBox>> $processBoxes interval => boxes in MTS order, then ''
     * @param list<OverviewCount>                 $routes       visits per arrival route (ems, self, unknown); of all visits
     * @param list<OverviewCount>                 $links        visits per link status (automatic, confirmed, not_linked); of all visits
     */
    public function __construct(
        public EncounterPeriod $period,
        public EncounterCoverage $coverage,
        public EncounterCoverage $allTimeCoverage,
        public array $routeLink,
        public array $categories,
        public array $diagrams,
        public int $diagramsShown,
        public array $dispositions,
        public array $careLevels,
        public int $careLevelDenominator,
        public array $processTimes,
        public TimeGrain $grain = TimeGrain::Month,
        public array $timeSeries = [],
        public array $processBoxes = [],
        public array $routes = [],
        public array $links = [],
    ) {
    }

    /** @psalm-suppress PossiblyUnusedMethod Called by the template. */
    public function isEmpty(): bool
    {
        return 0 === $this->coverage->visits;
    }
}
