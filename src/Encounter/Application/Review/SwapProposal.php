<?php

declare(strict_types=1);

namespace App\Encounter\Application\Review;

use App\Encounter\Application\Matching\PlannedMatch;

/**
 * A targeted swap the match review proposes (ADR 029, #1054); only a person applies it.
 *
 * Allocation A is automatically linked to visit W, but the unlinked visit V fits A:
 * - `chain`: W also fits the unlinked allocation B. Applying links A–V and B–W: one link more.
 * - `replacement`: V fits A better than W (more points). Applying links A–V; W becomes unlinked.
 *
 * Either way the pair A–W is rejected, as a person decided that A belongs to V.
 */
final readonly class SwapProposal
{
    public const string CHAIN = 'chain';

    public const string REPLACEMENT = 'replacement';

    public function __construct(
        public string $kind,
        /** A */
        public int $allocationId,
        /** W, linked to A now */
        public int $currentEncounterId,
        /** Points of A–W */
        public int $currentPoints,
        /** A–V, scored in the review window */
        public PlannedMatch $newLink,
        /** B–W for a chain, scored in the review window */
        public ?PlannedMatch $otherLink = null,
    ) {
    }

    public function newEncounterId(): int
    {
        return $this->newLink->encounterId;
    }

    public function otherAllocationId(): ?int
    {
        return $this->otherLink?->allocationId;
    }

    /** Points of the links after the swap minus the points before. */
    public function pointsGain(): int
    {
        return $this->newLink->points + ($this->otherLink->points ?? 0) - $this->currentPoints;
    }

    /** Effective links after the swap minus before: 1 for a chain, 0 for a replacement. */
    public function linkGain(): int
    {
        return $this->otherLink instanceof PlannedMatch ? 1 : 0;
    }

    /**
     * Stable identity for the form: the swap is applied only while the same proposal still exists.
     */
    public function key(): string
    {
        return sprintf('%d:%d:%d:%d', $this->allocationId, $this->currentEncounterId, $this->newEncounterId(), $this->otherAllocationId() ?? 0);
    }
}
