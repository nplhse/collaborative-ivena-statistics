<?php

declare(strict_types=1);

namespace App\Encounter\Application\Validation;

use App\Encounter\Application\Contract\MatchCandidateReaderInterface;
use App\Encounter\Application\Contract\MatchReviewReaderInterface;
use App\Encounter\Application\Contract\MatchSwapFinderInterface;
use App\Encounter\Application\Matching\MatchingAllocation;
use App\Encounter\Application\Review\SwapProposal;
use App\Encounter\Domain\Enum\MatchStatus;

/**
 * Decides how a validation verdict links allocation A and visit V (#1057), on the links as they are now.
 *
 * A link is never moved silently:
 * - V linked to another allocation C: only the review's swap proposal of C that hands V to A applies
 *   (a chain: C takes another visit, A takes V); otherwise refused, decide in C's review.
 * - A linked to another visit X (not the one the verdict releases): only the review's swap proposal of A
 *   from X to V applies; otherwise refused, decide in A's review.
 * - Otherwise the pair is confirmed; a pair confirmed already needs nothing.
 *
 * Swaps are the review's (ADR 030, {@see SwapProposal}): automatic links only, a confirmed link stays.
 */
final readonly class ValidationLinkPlanner
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private MatchReviewReaderInterface $review,
        private MatchCandidateReaderInterface $input,
        private MatchSwapFinderInterface $swaps,
    ) {
    }

    /**
     * @param int|null $releasedEncounterId the visit whose pair with A the verdict rejects first (the sampled visit)
     */
    public function plan(int $hospitalId, int $allocationId, int $encounterId, ?int $releasedEncounterId = null): ValidationLinkPlan
    {
        $visitLink = null;
        $allocationLink = null;
        foreach ($this->review->rowsTouching($allocationId, [$encounterId]) as $row) {
            if ($row['allocation_id'] === $allocationId && $row['encounter_id'] === $encounterId && MatchStatus::Confirmed === $row['status']) {
                return ValidationLinkPlan::linked();
            }
            if (!$row['status']->isEffective()) {
                continue;
            }
            if ($row['encounter_id'] === $encounterId && $row['allocation_id'] !== $allocationId) {
                $visitLink = $row;
            } elseif ($row['allocation_id'] === $allocationId && $row['encounter_id'] !== $encounterId && $row['encounter_id'] !== $releasedEncounterId) {
                $allocationLink = $row;
            }
        }

        if (null !== $visitLink) {
            $other = $visitLink['allocation_id'];
            $swap = $this->swapOf($hospitalId, $other, static fn (SwapProposal $p): bool => $p->currentEncounterId === $encounterId && $p->otherAllocationId() === $allocationId);
            if (!$swap instanceof SwapProposal || null !== $allocationLink) {
                return ValidationLinkPlan::refused($other);
            }

            return ValidationLinkPlan::swap($swap);
        }

        if (null !== $allocationLink) {
            $swap = $this->swapOf($hospitalId, $allocationId, static fn (SwapProposal $p): bool => $p->currentEncounterId === $allocationLink['encounter_id'] && $p->newEncounterId() === $encounterId);

            return $swap instanceof SwapProposal ? ValidationLinkPlan::swap($swap) : ValidationLinkPlan::refused($allocationId);
        }

        return ValidationLinkPlan::confirm();
    }

    /**
     * @param callable(SwapProposal): bool $matches
     */
    private function swapOf(int $hospitalId, int $allocationId, callable $matches): ?SwapProposal
    {
        $allocation = $this->input->allocation($hospitalId, $allocationId);
        if (!$allocation instanceof MatchingAllocation) {
            return null;
        }

        foreach ($this->swaps->forAllocation($hospitalId, $allocation) as $swap) {
            if ($matches($swap)) {
                return $swap;
            }
        }

        return null;
    }
}
