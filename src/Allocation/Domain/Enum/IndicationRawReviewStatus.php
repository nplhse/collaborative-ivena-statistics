<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Enum;

enum IndicationRawReviewStatus: string
{
    case Unreviewed = 'unreviewed';
    case Matched = 'matched';
    case NotMatchable = 'not_matchable';
    case Ignored = 'ignored';
    case NeedsReview = 'needs_review';

    public function isOpen(): bool
    {
        return self::Unreviewed === $this || self::NeedsReview === $this;
    }
}
