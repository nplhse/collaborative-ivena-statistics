<?php

declare(strict_types=1);

namespace App\Encounter\Application\Review;

use App\Encounter\Application\Contract\MatchReviewReaderInterface;
use App\Encounter\Application\Contract\MatchSwapFinderInterface;

/**
 * Walks through the allocations of a review segment in list order (#1054), like the indication review
 * worklist: "start" opens the first one, "skip" and every decision continue with the next one.
 *
 * After a decision the current allocation usually leaves its segment (a confirmed proposal is no longer a
 * proposal), so the items behind it move up by one. The caller therefore passes the position the
 * allocation had when its page was shown: if it is still in the segment the next item follows it,
 * otherwise the item now at that position is next.
 */
final readonly class MatchReviewNavigator
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private MatchReviewReaderInterface $review,
        private MatchSwapFinderInterface $swaps,
    ) {
    }

    public function first(int $hospitalId, ReviewContext $context): ?int
    {
        return $this->ids($hospitalId, $context)[0] ?? null;
    }

    /**
     * Position of the allocation in its segment, or null when it is not (or no longer) part of it.
     */
    public function position(int $hospitalId, ReviewContext $context, int $allocationId): ?int
    {
        $index = array_search($allocationId, $this->ids($hospitalId, $context), true);

        return false === $index ? null : $index;
    }

    public function next(int $hospitalId, ReviewContext $context, int $allocationId, ?int $position): ?int
    {
        $ids = $this->ids($hospitalId, $context);
        $index = array_search($allocationId, $ids, true);
        if (false !== $index) {
            return $ids[$index + 1] ?? null;
        }

        return null === $position ? null : ($ids[$position] ?? null);
    }

    /**
     * @return list<int>
     */
    private function ids(int $hospitalId, ReviewContext $context): array
    {
        if (!$context->filter->isNavigable()) {
            return [];
        }

        if (ReviewFilter::Swaps === $context->filter) {
            return array_map(
                static fn (SwapProposal $proposal): int => $proposal->allocationId,
                $this->swaps->between($hospitalId, $context->period->from, $context->period->toExclusive()),
            );
        }

        return $this->review->segmentAllocationIds($hospitalId, $context);
    }
}
