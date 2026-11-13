<?php

declare(strict_types=1);

namespace App\Encounter\Application\Overview;

/**
 * Bucket size of the visits time series (#1055). The statistics page chooses it from the statistics
 * period as every statistics time series does: a month period in days, every other period in months.
 */
enum TimeGrain: string
{
    case Day = 'day';
    case Month = 'month';

    /** Key of a bucket starting at $start, as the statistics time series use it: `YYYY-MM-DD` or `YYYY-MM`. */
    public function key(\DateTimeImmutable $start): string
    {
        return self::Day === $this ? $start->format('Y-m-d') : $start->format('Y-m');
    }
}
