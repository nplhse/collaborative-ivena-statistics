<?php

declare(strict_types=1);

namespace App\Research\Domain\Enum;

/**
 * How a voting round ended (ADR 045).
 */
enum RoundOutcome: string
{
    case Approved = 'approved';
    case RevisionRequested = 'revision_requested';
    case Rejected = 'rejected';
    /** Fewer than two thirds of the eligible members voted by the deadline; the request counts as rejected. */
    case QuorumMissed = 'quorum_missed';
    case Vetoed = 'vetoed';
    /** The applicant withdrew the request while the round was open. */
    case Cancelled = 'cancelled';

    public function labelKey(): string
    {
        return 'data_request.round.outcome.'.$this->value;
    }
}
