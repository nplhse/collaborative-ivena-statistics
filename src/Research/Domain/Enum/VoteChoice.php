<?php

declare(strict_types=1);

namespace App\Research\Domain\Enum;

/**
 * A Board member's vote in a round (ADR 045). "Revision" is a conditional approval; a veto stops the request until
 * an administrator resolves it and needs a reason.
 */
enum VoteChoice: string
{
    case Approve = 'approve';
    case Revision = 'revision';
    case Reject = 'reject';
    case Veto = 'veto';

    public function labelKey(): string
    {
        return 'data_request.vote.choice.'.$this->value;
    }

    public function requiresReason(): bool
    {
        return self::Veto === $this;
    }
}
