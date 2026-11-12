<?php

declare(strict_types=1);

namespace App\Encounter\Application\Overview;

/**
 * Bucket size of the visits time series (#1055): calendar weeks (Monday) for spans up to
 * MAX_WEEK_SPAN_DAYS, calendar months above.
 */
enum TimeGrain: string
{
    case Week = 'week';
    case Month = 'month';

    public const int MAX_WEEK_SPAN_DAYS = 120;

    public static function forSpan(?\DateTimeImmutable $first, ?\DateTimeImmutable $last): self
    {
        if (!$first instanceof \DateTimeImmutable || !$last instanceof \DateTimeImmutable) {
            return self::Week;
        }

        return $first->diff($last)->days > self::MAX_WEEK_SPAN_DAYS ? self::Month : self::Week;
    }
}
