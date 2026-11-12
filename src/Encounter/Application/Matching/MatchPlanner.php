<?php

declare(strict_types=1);

namespace App\Encounter\Application\Matching;

use App\Encounter\Domain\Enum\ArrivalRoute;
use App\Encounter\Domain\Enum\MatchStatus;

/**
 * Decides which pairs of allocations and ED visits become automatic links or proposals (matcher v1,
 * ADR 029). Pure: it reads nothing and writes nothing.
 *
 * Score: each candidate visit of an allocation weighs 2^points (sum of the feature points,
 * {@see FeatureScorer}); a "no match" option weighs 2^NO_MATCH_POINTS. The score of a candidate is its
 * share of the total weight, so two similar candidates both score low. Example: a single candidate with
 * 9 points scores 512 / (16 + 512) = 0.9697.
 *
 * Passes: pass 1 compares EMS visits in the narrow window, pass 2 every unreserved visit in the wide
 * window, each for the allocations without an effective link. Within a pass every allocation's claims
 * are computed before anything is reserved. A claim at or above the auto-accept threshold becomes
 * `auto_accepted` and reserves both sides, unless another allocation reaches the threshold for the same
 * visit in that pass: then every such claim becomes a proposal marked as conflict and nothing is
 * reserved. Claims at or above the proposal threshold become proposals.
 *
 * Existing effective links and manual decisions come in as reserved sides and rejected pairs: a run only
 * fills gaps and never proposes a rejected pair again. Proposals touching a side that is linked at the end
 * are dropped; a pair proposed in an earlier pass keeps that pass's result.
 */
final readonly class MatchPlanner
{
    public const string VERSION = '1.0.0';

    /** Weight of the "no match" option in points. */
    public const int NO_MATCH_POINTS = 4;

    private const int SCORE_PRECISION = 4;

    public function __construct(
        private MatchingSettings $settings,
        private FeatureScorer $scorer = new FeatureScorer(),
    ) {
    }

    /**
     * @param list<MatchingAllocation> $allocations         allocations to match (without effective link or not, see below)
     * @param list<MatchingEncounter>  $encounters          candidate visits of the same hospital
     * @param array<int, true>         $reservedAllocations allocation ids with an effective link
     * @param array<int, true>         $reservedEncounters  visit ids with an effective link
     * @param array<string, true>      $rejectedPairs       keys "allocationId:encounterId" of rejected pairs
     *
     * @return list<PlannedMatch> automatic links first, then proposals; each in order of allocation and visit id
     */
    public function plan(array $allocations, array $encounters, array $reservedAllocations = [], array $reservedEncounters = [], array $rejectedPairs = []): array
    {
        usort($allocations, static fn (MatchingAllocation $a, MatchingAllocation $b): int => $a->id <=> $b->id);
        $timeline = $this->timeline($encounters);

        /** @var array<string, PlannedMatch> $links */
        $links = [];
        /** @var array<string, PlannedMatch> $proposals */
        $proposals = [];

        foreach ($this->settings->passes() as $pass => $window) {
            /** @var array<int, list<PlannedMatch>> $autoClaims visit id => claims at or above the auto-accept threshold */
            $autoClaims = [];
            foreach ($allocations as $allocation) {
                if (isset($reservedAllocations[$allocation->id])) {
                    continue;
                }

                foreach ($this->claims($allocation, $timeline, $window, $pass, $reservedEncounters, $rejectedPairs) as $claim) {
                    if ($claim->score >= $this->settings->autoAcceptThreshold) {
                        $autoClaims[$claim->encounterId][] = $claim;
                    } elseif ($claim->score >= $this->settings->proposalThreshold) {
                        $proposals[$this->key($claim)] ??= $claim;
                    }
                }
            }

            foreach ($autoClaims as $encounterId => $claims) {
                if (1 === \count($claims)) {
                    $link = $this->withStatus($claims[0], MatchStatus::AutoAccepted, false);
                    $links[$this->key($link)] = $link;
                    $reservedAllocations[$link->allocationId] = true;
                    $reservedEncounters[$encounterId] = true;

                    continue;
                }

                foreach ($claims as $claim) {
                    $key = $this->key($claim);
                    $proposals[$key] = $this->withStatus($proposals[$key] ?? $claim, MatchStatus::Proposed, true);
                }
            }
        }

        $open = array_filter(
            $proposals,
            static fn (PlannedMatch $proposal): bool => !isset($reservedAllocations[$proposal->allocationId]) && !isset($reservedEncounters[$proposal->encounterId]),
        );

        return [...$this->sorted($links), ...$this->sorted($open)];
    }

    /**
     * Scored candidates of one allocation in one pass, with the "no match" option in the denominator.
     *
     * @param array{0: list<int>, 1: list<MatchingEncounter>} $timeline
     * @param array<int, true>                                $reservedEncounters
     * @param array<string, true>                             $rejectedPairs
     *
     * @return list<PlannedMatch> status `proposed`, not yet filtered by threshold
     */
    private function claims(MatchingAllocation $allocation, array $timeline, PassWindow $window, int $pass, array $reservedEncounters, array $rejectedPairs): array
    {
        $candidates = [];
        foreach ($this->around($timeline, FeatureScorer::wallClockMinute($allocation->arrivalAt), $window) as $encounter) {
            if (isset($reservedEncounters[$encounter->id]) || isset($rejectedPairs[$allocation->id.':'.$encounter->id])) {
                continue;
            }

            if ($window->emsOnly && ArrivalRoute::Ems !== $encounter->arrivalRoute) {
                continue;
            }

            $minutes = FeatureScorer::timeDifferenceMinutes($allocation->arrivalAt, $encounter->anchorAt);
            if (!$window->contains($minutes, $allocation->age - $encounter->age)) {
                continue;
            }

            $contributions = $this->scorer->score($allocation, $encounter, $minutes);
            $candidates[] = [$encounter, $minutes, $contributions, (int) array_sum($contributions)];
        }

        if ([] === $candidates) {
            return [];
        }

        $total = $this->weight(self::NO_MATCH_POINTS);
        foreach ($candidates as [, , , $points]) {
            $total += $this->weight($points);
        }

        $claims = [];
        foreach ($candidates as [$encounter, $minutes, $contributions, $points]) {
            $claims[] = new PlannedMatch(
                allocationId: $allocation->id,
                encounterId: $encounter->id,
                status: MatchStatus::Proposed,
                score: round($this->weight($points) / $total, self::SCORE_PRECISION),
                points: $points,
                contributions: $contributions,
                pass: $pass,
                timeAnchor: $encounter->anchorSource,
                timeDifferenceMinutes: $minutes,
                conflict: false,
            );
        }

        return $claims;
    }

    /**
     * Visits ordered by matching anchor (then id) with their anchor minute, for range lookups.
     *
     * @param list<MatchingEncounter> $encounters
     *
     * @return array{0: list<int>, 1: list<MatchingEncounter>}
     */
    private function timeline(array $encounters): array
    {
        $keyed = array_map(static fn (MatchingEncounter $e): array => [FeatureScorer::wallClockMinute($e->anchorAt), $e->id, $e], $encounters);
        usort($keyed, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return [array_column($keyed, 0), array_column($keyed, 2)];
    }

    /**
     * Visits whose anchor lies in the pass window around the arrival minute, in anchor order.
     *
     * @param array{0: list<int>, 1: list<MatchingEncounter>} $timeline
     *
     * @return iterable<MatchingEncounter>
     */
    private function around(array $timeline, int $arrivalMinute, PassWindow $window): iterable
    {
        [$minutes, $encounters] = $timeline;
        $from = $arrivalMinute - $window->minutesBefore;
        $low = 0;
        $high = \count($minutes);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($minutes[$middle] < $from) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        $to = $arrivalMinute + $window->minutesAfter;
        for ($i = $low, $count = \count($minutes); $i < $count && $minutes[$i] <= $to; ++$i) {
            yield $encounters[$i];
        }
    }

    /**
     * 2^points as float (points may be negative).
     */
    private function weight(int $points): float
    {
        return 2.0 ** (float) $points;
    }

    private function withStatus(PlannedMatch $claim, MatchStatus $status, bool $conflict): PlannedMatch
    {
        return new PlannedMatch(
            $claim->allocationId,
            $claim->encounterId,
            $status,
            $claim->score,
            $claim->points,
            $claim->contributions,
            $claim->pass,
            $claim->timeAnchor,
            $claim->timeDifferenceMinutes,
            $conflict,
        );
    }

    private function key(PlannedMatch $match): string
    {
        return $match->allocationId.':'.$match->encounterId;
    }

    /**
     * @param array<string, PlannedMatch> $matches
     *
     * @return list<PlannedMatch>
     */
    private function sorted(array $matches): array
    {
        $matches = array_values($matches);
        usort($matches, static fn (PlannedMatch $a, PlannedMatch $b): int => [$a->allocationId, $a->encounterId] <=> [$b->allocationId, $b->encounterId]);

        return $matches;
    }
}
