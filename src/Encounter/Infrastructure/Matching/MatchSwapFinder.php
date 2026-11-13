<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Matching;

use App\Encounter\Application\Contract\MatchSwapFinderInterface;
use App\Encounter\Application\Matching\MatchingAllocation;
use App\Encounter\Application\Matching\MatchingSettings;
use App\Encounter\Application\Review\SwapPlanner;
use App\Encounter\Application\Review\SwapProposal;
use App\Encounter\Infrastructure\Query\MatchInputQuery;
use App\Encounter\Infrastructure\Query\MatchReviewQuery;

/**
 * Loads what the swap planner compares and returns the swap proposals whose allocation (A) arrives in
 * the given range (#1054).
 *
 * The other allocation of a chain (B) and the visits may lie outside the range: allocations are read with
 * a margin of one review window, visits with two.
 */
final readonly class MatchSwapFinder implements MatchSwapFinderInterface
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private MatchInputQuery $input,
        private MatchReviewQuery $review,
        private MatchingSettings $settings,
    ) {
    }

    /**
     * @return list<SwapProposal> best first
     */
    #[\Override]
    public function between(int $hospitalId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $margin = $this->settings->widestMinutesBefore() + $this->settings->widestMinutesAfter();
        $allocations = $this->input->allocations($hospitalId, $from->modify(sprintf('-%d minutes', $margin)), $to->modify(sprintf('+%d minutes', $margin)), null);
        if ([] === $allocations) {
            return [];
        }

        $encounters = $this->input->encounters($hospitalId, $from->modify(sprintf('-%d minutes', 2 * $margin)), $to->modify(sprintf('+%d minutes', 2 * $margin)), null);
        $state = $this->review->linkState($hospitalId, $from->modify(sprintf('-%d minutes', 2 * $margin)), $to->modify(sprintf('+%d minutes', 2 * $margin)));

        $inRange = [];
        foreach ($allocations as $allocation) {
            if ($allocation->arrivalAt >= $from && $allocation->arrivalAt < $to) {
                $inRange[$allocation->id] = true;
            }
        }

        $proposals = new SwapPlanner($this->settings)->plan(
            $allocations,
            $encounters,
            $state['automaticLinks'],
            $state['linkedAllocations'],
            $state['linkedEncounters'],
            $state['rejectedPairs'],
        );

        return array_values(array_filter($proposals, static fn (SwapProposal $p): bool => isset($inRange[$p->allocationId])));
    }

    /**
     * Swap proposals of one allocation (at most one).
     *
     * @return list<SwapProposal>
     */
    #[\Override]
    public function forAllocation(int $hospitalId, MatchingAllocation $allocation): array
    {
        $proposals = $this->between($hospitalId, $allocation->arrivalAt, $allocation->arrivalAt->modify('+1 minute'));

        return array_values(array_filter($proposals, static fn (SwapProposal $p): bool => $p->allocationId === $allocation->id));
    }
}
