<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Encounter\Application\Overview\EncounterCoverage;
use App\Encounter\Application\Overview\EncounterPeriod;
use App\Encounter\Application\Overview\ProcessInterval;
use App\Encounter\Application\Overview\ProcessTimeStatistics;

/**
 * Descriptive aggregates of the stored visits of exactly one hospital (#1055). Every method counts
 * the visits whose time anchor lies in the period, all key generations, and never another hospital.
 * Counts are raw; small-cell suppression is applied by the overview (ADR 012).
 */
interface EncounterOverviewReaderInterface
{
    public const string NOT_LINKED = 'not_linked';

    public function coverage(int $hospitalId, EncounterPeriod $period): EncounterCoverage;

    /**
     * Visits per arrival route (`ems`, `self`, `unknown`) and IVENA link status: `auto_accepted`,
     * `confirmed` (the effective links) or NOT_LINKED (no effective link; open proposals and rejected
     * pairs count as not linked).
     *
     * @return array<string, array<string, int>> route => link status => visits
     */
    public function visitsByRouteAndLink(int $hospitalId, EncounterPeriod $period): array;

    /**
     * Visits per initial MTS category; '' = no category documented.
     *
     * @return array<string, int>
     */
    public function visitsByCategory(int $hospitalId, EncounterPeriod $period): array;

    /**
     * Visits per initial MTS diagram code; '' = no diagram documented.
     *
     * @return array<string, int>
     */
    public function visitsByDiagram(int $hospitalId, EncounterPeriod $period): array;

    /**
     * Visits per disposition code; '' = no disposition documented.
     *
     * @return array<string, int>
     */
    public function visitsByDisposition(int $hospitalId, EncounterPeriod $period): array;

    /**
     * Visits with disposition `admitted` or `transferred_external` per care level; '' = no care level.
     *
     * @return array<string, int>
     */
    public function visitsByCareLevel(int $hospitalId, EncounterPeriod $period): array;

    public function processTime(int $hospitalId, EncounterPeriod $period, ProcessInterval $interval): ProcessTimeStatistics;

    /**
     * Hospitals that have at least one stored visit, as id => name, ordered by name.
     *
     * @return array<int, string>
     */
    public function hospitalsWithVisits(): array;
}
