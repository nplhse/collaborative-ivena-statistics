<?php

declare(strict_types=1);

namespace App\Encounter\Application\Review;

use App\Encounter\Domain\Enum\MatchStatus;

/**
 * Lists of the match review (#1054). Pairs are listed by the IVENA arrival of their allocation, visits by
 * their matching anchor (ED admission, else first assessment contact).
 */
enum ReviewFilter: string
{
    /** Open proposals without an open conflict. */
    case Proposed = 'proposed';
    /** Open proposals in conflict: another allocation also claims the visit. */
    case Conflicts = 'conflicts';
    /** Automatic links (`auto_accepted`). */
    case Automatic = 'automatic';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    /** Allocations without link and without proposal. */
    case NoProposal = 'no_proposal';
    /** Visits without link, optionally by arrival route. */
    case UnlinkedVisits = 'unlinked_visits';
    /** Targeted swap proposals. */
    case Swaps = 'swaps';

    public function status(): ?MatchStatus
    {
        return match ($this) {
            self::Proposed, self::Conflicts => MatchStatus::Proposed,
            self::Automatic => MatchStatus::AutoAccepted,
            self::Confirmed => MatchStatus::Confirmed,
            self::Rejected => MatchStatus::Rejected,
            self::NoProposal, self::UnlinkedVisits, self::Swaps => null,
        };
    }

    /**
     * Segments whose items are allocations, so "start" and "skip" can walk through them. Unlinked visits
     * have no allocation of their own.
     */
    public function isNavigable(): bool
    {
        return self::UnlinkedVisits !== $this;
    }

    /**
     * Segments that list pairs and can be sorted by agreement and time difference as well.
     */
    public function listsPairs(): bool
    {
        return $this->status() instanceof MatchStatus;
    }

    /** @psalm-suppress PossiblyUnusedMethod Used by the review templates. */
    public function labelKey(): string
    {
        return 'encounter.review.filter.'.$this->value;
    }
}
