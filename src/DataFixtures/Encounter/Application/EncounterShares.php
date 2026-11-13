<?php

declare(strict_types=1);

namespace App\DataFixtures\Encounter\Application;

/**
 * How many visits of each group the encounter generator creates (#1052).
 *
 * Every count is an exact rounded share, not a random draw, so tests can check it by hand: with
 * 100 allocations and the defaults there are 85 linked visits, 13 EMS arrivals without allocation,
 * 128 self-presenters, 4 visits with unknown arrival route and 7 repeat visits (237 visits). The
 * shares are general knowledge, not measured.
 */
final readonly class EncounterShares
{
    /**
     * @param float $linked               share of the allocations that have an ED visit (the rest went past the ED or were not documented)
     * @param float $emsWithoutAllocation EMS arrivals without allocation, per linked visit
     * @param float $selfPresenters       self-presenters, per linked visit
     * @param float $unknownRoute         visits with arrival mode `other` or empty, per linked visit
     * @param float $repeatVisits         further visits under the case number of an earlier visit, per visit of the other groups
     * @param float $timeOutliers         linked visits whose ED admission lies outside the pass-1 window of ADR 030
     * @param float $ageNoise             linked visits whose age differs from IVENA by 1–5 years
     * @param float $missingAdmission     visits without ED admission time (first assessment contact only), of all visits
     * @param float $lateAssessment       visits whose first assessment contact is documented only after the first export, of all visits
     */
    public function __construct(
        public float $linked = 0.85,
        public float $emsWithoutAllocation = 0.15,
        public float $selfPresenters = 1.5,
        public float $unknownRoute = 0.05,
        public float $repeatVisits = 0.03,
        public float $timeOutliers = 0.05,
        public float $ageNoise = 0.20,
        public float $missingAdmission = 0.05,
        public float $lateAssessment = 0.02,
    ) {
        foreach (get_object_vars($this) as $name => $share) {
            if ($share < 0.0) {
                throw new \InvalidArgumentException(sprintf('Share "%s" must not be negative, got %s.', $name, $share));
            }
        }

        foreach (['linked' => $linked, 'timeOutliers' => $timeOutliers, 'ageNoise' => $ageNoise] as $name => $share) {
            if ($share > 1.0) {
                throw new \InvalidArgumentException(sprintf('Share "%s" must be at most 1, got %s.', $name, $share));
            }
        }

        if ($missingAdmission + $lateAssessment > 1.0) {
            throw new \InvalidArgumentException('Missing admissions and late assessments together must be at most 1.');
        }
    }

    public static function count(int $base, float $share): int
    {
        return (int) round((float) $base * $share);
    }
}
