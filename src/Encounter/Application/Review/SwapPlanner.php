<?php

declare(strict_types=1);

namespace App\Encounter\Application\Review;

use App\Encounter\Application\Matching\MatchingAllocation;
use App\Encounter\Application\Matching\MatchingEncounter;
use App\Encounter\Application\Matching\MatchingSettings;
use App\Encounter\Application\Matching\MatchPlanner;
use App\Encounter\Application\Matching\PlannedMatch;

/**
 * Finds targeted swaps where an unlinked visit suggests a better overall assignment (ADR 029, #1054).
 * Pure: it reads nothing and writes nothing; a person applies a swap in the review.
 *
 * Only automatic links (`auto_accepted`) are considered: a confirmed link was decided by a person and
 * stays. For an automatic link A–W and an unlinked visit V in A's review window (pair A–V not rejected):
 *
 * 1. Chain: an unlinked allocation B has W in its review window (B–W not rejected). A–V and B–W give one
 *    link more. Among several B the one with the most points (then the lowest id) is chosen.
 * 2. Replacement: otherwise, if A–V has more points than A–W.
 *
 * Every new pair must reach the proposal threshold as the only candidate ("no match" option only,
 * {@see MatchPlanner::agreementAlone()}), so a swap never proposes a pair the matcher would not propose
 * on its own. Per allocation the best proposal is kept (chain before replacement, then points gain, then
 * the lowest visit id); proposals that share a visit or an allocation with a better one are dropped.
 */
final readonly class SwapPlanner
{
    public function __construct(
        private MatchingSettings $settings,
    ) {
    }

    /**
     * @param list<MatchingAllocation> $allocations       allocations of the period (linked or not)
     * @param list<MatchingEncounter>  $encounters        candidate visits around them
     * @param array<int, int>          $automaticLinks    allocation id => visit id of `auto_accepted` links
     * @param array<int, true>         $linkedAllocations allocation ids with an effective link
     * @param array<int, true>         $linkedEncounters  visit ids with an effective link
     * @param array<string, true>      $rejectedPairs     keys "allocationId:encounterId"
     *
     * @return list<SwapProposal> best first
     */
    public function plan(array $allocations, array $encounters, array $automaticLinks, array $linkedAllocations, array $linkedEncounters, array $rejectedPairs): array
    {
        $planner = new MatchPlanner($this->settings);
        $window = $this->settings->reviewWindow();

        /** @var array<int, array<int, PlannedMatch>> $candidates allocation id => visit id => scored pair */
        $candidates = [];
        foreach ($allocations as $allocation) {
            foreach ($planner->candidates($allocation, $encounters, $window, 2) as $candidate) {
                $candidates[$allocation->id][$candidate->encounterId] = $candidate;
            }
        }

        $unlinkedAllocations = array_values(array_filter(
            array_map(static fn (MatchingAllocation $a): int => $a->id, $allocations),
            static fn (int $id): bool => !isset($linkedAllocations[$id]),
        ));
        sort($unlinkedAllocations);

        $proposals = [];
        foreach ($automaticLinks as $allocationId => $currentEncounterId) {
            $own = $candidates[$allocationId] ?? [];
            $current = $own[$currentEncounterId] ?? null;
            if (!$current instanceof PlannedMatch) {
                continue;
            }

            $other = $this->otherAllocationFor($currentEncounterId, $unlinkedAllocations, $candidates, $rejectedPairs);
            $best = null;
            foreach ($own as $encounterId => $candidate) {
                if (isset($linkedEncounters[$encounterId]) || isset($rejectedPairs[$allocationId.':'.$encounterId]) || !$this->plausible($candidate)) {
                    continue;
                }

                if ($other instanceof PlannedMatch) {
                    $proposal = new SwapProposal(SwapProposal::CHAIN, $allocationId, $currentEncounterId, $current->points, $candidate, $other);
                } elseif ($candidate->points > $current->points) {
                    $proposal = new SwapProposal(SwapProposal::REPLACEMENT, $allocationId, $currentEncounterId, $current->points, $candidate);
                } else {
                    continue;
                }

                if (!$best instanceof SwapProposal || $this->rank($proposal) < $this->rank($best)) {
                    $best = $proposal;
                }
            }

            if ($best instanceof SwapProposal) {
                $proposals[] = $best;
            }
        }

        usort($proposals, fn (SwapProposal $a, SwapProposal $b): int => $this->rank($a) <=> $this->rank($b));

        return $this->withoutOverlaps($proposals);
    }

    /**
     * @param list<int>                            $unlinkedAllocations
     * @param array<int, array<int, PlannedMatch>> $candidates
     * @param array<string, true>                  $rejectedPairs
     */
    private function otherAllocationFor(int $encounterId, array $unlinkedAllocations, array $candidates, array $rejectedPairs): ?PlannedMatch
    {
        $best = null;
        foreach ($unlinkedAllocations as $allocationId) {
            $pair = $candidates[$allocationId][$encounterId] ?? null;
            if (!$pair instanceof PlannedMatch || isset($rejectedPairs[$allocationId.':'.$encounterId]) || !$this->plausible($pair)) {
                continue;
            }

            if (!$best instanceof PlannedMatch || $pair->points > $best->points) {
                $best = $pair;
            }
        }

        return $best;
    }

    private function plausible(PlannedMatch $pair): bool
    {
        return MatchPlanner::agreementAlone($pair->points) >= $this->settings->proposalThreshold;
    }

    /**
     * @return array{int, int, int, int}
     */
    private function rank(SwapProposal $proposal): array
    {
        return [-$proposal->linkGain(), -$proposal->pointsGain(), $proposal->allocationId, $proposal->newEncounterId()];
    }

    /**
     * @param list<SwapProposal> $proposals ranked
     *
     * @return list<SwapProposal>
     */
    private function withoutOverlaps(array $proposals): array
    {
        $usedAllocations = [];
        $usedEncounters = [];
        $kept = [];
        foreach ($proposals as $proposal) {
            $allocations = array_filter([$proposal->allocationId, $proposal->otherAllocationId()]);
            $encounters = [$proposal->currentEncounterId, $proposal->newEncounterId()];
            if (array_any($allocations, static fn (int $id): bool => isset($usedAllocations[$id]))
                || array_any($encounters, static fn (int $id): bool => isset($usedEncounters[$id]))) {
                continue;
            }

            foreach ($allocations as $id) {
                $usedAllocations[$id] = true;
            }
            foreach ($encounters as $id) {
                $usedEncounters[$id] = true;
            }
            $kept[] = $proposal;
        }

        return $kept;
    }
}
