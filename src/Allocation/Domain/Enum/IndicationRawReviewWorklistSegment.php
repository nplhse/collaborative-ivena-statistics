<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Enum;

enum IndicationRawReviewWorklistSegment: string
{
    case Open = 'open';
    case Unreviewed = 'unreviewed';
    case NeedsReview = 'needs_review';
    case New = 'new';
    case TopOpen = 'top_open';
    case Matched = 'matched';
    case NotMatchable = 'not_matchable';
    case Ignored = 'ignored';

    /**
     * @return list<self>
     */
    public static function tabOrder(): array
    {
        return [
            self::Open,
            self::Unreviewed,
            self::NeedsReview,
            self::New,
            self::Matched,
            self::NotMatchable,
            self::Ignored,
        ];
    }

    public function forWorklist(): self
    {
        return self::TopOpen === $this ? self::Open : $this;
    }

    public function isOpenSegment(): bool
    {
        return self::Open === $this || self::New === $this || self::TopOpen === $this;
    }
}
