<?php

declare(strict_types=1);

namespace App\Encounter\Application\Review;

/**
 * Segments (tabs) of the match review worklist (#1054). Each lists allocations of the period by their
 * IVENA arrival; finer distinctions are filters of a segment ({@see ReviewContext}).
 */
enum ReviewFilter: string
{
    /** Allocations a person has to decide on: open proposal (also in conflict) or swap proposal; one row each. */
    case Open = 'open';
    /** Allocations without link and without proposal. */
    case NoProposal = 'no_proposal';
    /** Decided pairs: automatic or confirmed links and rejected pairs; one row per pair. */
    case Done = 'done';

    /** @psalm-suppress PossiblyUnusedMethod Used by the review templates. */
    public function labelKey(): string
    {
        return 'encounter.review.filter.'.$this->value;
    }
}
