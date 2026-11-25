<?php

declare(strict_types=1);

namespace App\Research\Domain\Enum;

/**
 * Places of the `data_request` state machine (ADR 045). The values are the place names in `workflow.yaml`.
 */
enum DataRequestStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Returned = 'returned';
    case InReview = 'in_review';
    case RevisionRequested = 'revision_requested';
    case Stalled = 'stalled';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Withdrawn = 'withdrawn';

    /** A final request takes no further transition. */
    public function isFinal(): bool
    {
        return \in_array($this, [self::Rejected, self::Expired, self::Withdrawn], true);
    }

    /** The applicant may change the current version: a draft, or a request handed back for changes. */
    /** Submitted and not decided yet: the formal check, a vote, a requested revision or a stop by veto. */
    public function isUnderReview(): bool
    {
        return \in_array($this, [self::Submitted, self::Returned, self::InReview, self::RevisionRequested, self::Stalled], true);
    }

    public function isEditable(): bool
    {
        return \in_array($this, [self::Draft, self::Returned, self::RevisionRequested], true);
    }

    public function labelKey(): string
    {
        return 'data_request.status.'.$this->value;
    }

    /** Tabler badge colour, the same in lists and on the detail page. */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Draft => 'secondary',
            self::Submitted, self::Returned => 'azure',
            self::InReview => 'blue',
            self::RevisionRequested, self::Stalled => 'yellow',
            self::Approved => 'green',
            self::Rejected => 'red',
            self::Expired, self::Withdrawn => 'secondary',
        };
    }
}
