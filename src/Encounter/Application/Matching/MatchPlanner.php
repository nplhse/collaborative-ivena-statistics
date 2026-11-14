<?php

declare(strict_types=1);

namespace App\Encounter\Application\Matching;

use App\Encounter\Application\Plausibility\PlausibilityBonus;
use App\Encounter\Application\Plausibility\PlausibilityHit;
use App\Encounter\Domain\Enum\ArrivalRoute;
use App\Encounter\Domain\Enum\MatchStatus;

/**
 * Decides which pairs of allocations and ED visits become automatic links or proposals (ADR 030; by
 * default with the production points, matcher v1.2). Pure: it reads nothing and writes nothing.
 *
 * Score: each candidate visit of an allocation weighs 2^points (sum of the feature points,
 * {@see FeatureScorer}); a "no match" option weighs 2^noMatchPoints ({@see MatchWeights}, every version: 4).
 * The score of a candidate is its share of the total weight, so two similar candidates both score low.
 * Example: a single candidate with 9 points scores 512 / (16 + 512) = 0.9697.
 *
 * Passes: pass 1 compares EMS visits in the narrow window, pass 2 every unreserved visit in the wide
 * window, each for the allocations without an effective link. Within a pass every allocation's claims
 * are computed before anything is reserved. A claim at or above the auto-accept threshold becomes
 * `auto_accepted` and reserves both sides, unless another allocation reaches the threshold for the same
 * visit in that pass: then every such claim becomes a proposal marked as conflict and nothing is
 * reserved. Claims at or above the proposal threshold become proposals.
 *
 * Plausibility bonus (since v1.2, ADR 030 amendment, ADR 033): in pass 2 only, every candidate gets the bonus of its
 * PZC × MTS diagram cell from the given snapshot ({@see PlausibilityBonus}), at most {@see MatchWeights::$plausibilityCap},
 * as contribution `plausibility` (0 included). It competes like any other point. A pair is marked decisive when its
 * score with the bonus removed from every candidate of the allocation stays below the threshold of its status
 * (auto-accept for an automatic link, proposal for a proposal). Example: a sex mismatch with everything else matching
 * scores 4 − 2 + 2 + 1 = 5 points (0.6667, a proposal); +3 gives 8 points, 256 / 272 = 0.9412, an automatic link
 * decided by the bonus.
 *
 * Existing effective links and manual decisions come in as reserved sides and rejected pairs: a run only
 * fills gaps and never proposes a rejected pair again. Proposals touching a side that is linked at the end
 * are dropped; a pair proposed in an earlier pass keeps that pass's result.
 */
final readonly class MatchPlanner
{
    /** Version of the production matcher, stored with every pair it writes. */
    public const string VERSION = MatchWeights::VERSION_1_2;

    /** Weight of the "no match" option of the production matcher, in points. */
    public const int NO_MATCH_POINTS = MatchWeights::NO_MATCH_POINTS_1_2;

    /** The only pass that adds the plausibility bonus. */
    public const int PLAUSIBILITY_PASS = 2;

    public const int SCORE_PRECISION = 4;

    private FeatureScorer $scorer;

    private PlausibilityBonus $plausibility;

    /**
     * @param PlausibilityBonus|null $plausibility snapshot for the bonus of pass 2; null: no bonus
     */
    public function __construct(
        private MatchingSettings $settings,
        private MatchWeights $weights = new MatchWeights(),
        ?PlausibilityBonus $plausibility = null,
    ) {
        $this->scorer = new FeatureScorer($weights);
        $this->plausibility = $plausibility ?? PlausibilityBonus::none();
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
                        $proposals[$this->key($claim)] ??= $this->withStatus($claim, MatchStatus::Proposed, false);
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
     * Every candidate of one allocation in the given window, scored against each other and the "no match"
     * option, without reservations or rejected pairs (match review, #1054). The review uses the widest
     * window ({@see MatchingSettings::reviewWindow()}), so a candidate's agreement there can differ from the
     * score stored by the pass that found the pair.
     *
     * @param list<MatchingEncounter> $encounters
     *
     * @return list<PlannedMatch> status `proposed`, ordered by points (highest first), then visit id
     */
    public function candidates(MatchingAllocation $allocation, array $encounters, PassWindow $window, int $pass): array
    {
        $candidates = $this->claims($allocation, $this->timeline($encounters), $window, $pass, [], []);
        usort($candidates, static fn (PlannedMatch $a, PlannedMatch $b): int => [$b->points, $a->encounterId] <=> [$a->points, $b->encounterId]);

        return $candidates;
    }

    /**
     * Agreement of a pair that is the only candidate under the production points:
     * 2^points / (2^points + 2^NO_MATCH_POINTS).
     */
    public static function agreementAlone(int $points): float
    {
        return MatchWeights::production()->agreementAlone($points);
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
        $withBonus = self::PLAUSIBILITY_PASS === $pass && $this->weights->plausibilityCap > 0;
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
            $basePoints = (int) array_sum($contributions);
            $hit = null;
            if ($withBonus) {
                $hit = $this->plausibility->lookup($allocation->pzc, $encounter->mtsDiagram);
                $contributions[MatchFeature::Plausibility->value] = min($this->weights->plausibilityCap, $hit->points);
            }

            $candidates[] = [$encounter, $minutes, $contributions, (int) array_sum($contributions), $basePoints, $hit];
        }

        if ([] === $candidates) {
            return [];
        }

        $total = $this->weight($this->weights->noMatchPoints);
        $totalWithoutBonus = $total;
        foreach ($candidates as [, , , $points, $basePoints]) {
            $total += $this->weight($points);
            $totalWithoutBonus += $this->weight($basePoints);
        }

        $claims = [];
        foreach ($candidates as [$encounter, $minutes, $contributions, $points, $basePoints, $hit]) {
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
                plausibilitySnapshotId: $hit?->snapshotId,
                plausibilityLevel: $hit?->level,
                scoreWithoutBonus: $hit instanceof PlausibilityHit ? round($this->weight($basePoints) / $totalWithoutBonus, self::SCORE_PRECISION) : null,
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

    /**
     * The claim with its final status; the bonus is decisive when the score without it stays below the threshold of
     * that status.
     */
    private function withStatus(PlannedMatch $claim, MatchStatus $status, bool $conflict): PlannedMatch
    {
        $threshold = MatchStatus::AutoAccepted === $status ? $this->settings->autoAcceptThreshold : $this->settings->proposalThreshold;

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
            $claim->plausibilitySnapshotId,
            $claim->plausibilityLevel,
            $claim->scoreWithoutBonus,
            null !== $claim->scoreWithoutBonus && $claim->scoreWithoutBonus < $threshold,
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
