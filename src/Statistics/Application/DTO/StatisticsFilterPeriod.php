<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO;

/**
 * Period identifiers used in URLs (`period`, `comparison_period`), forms and saved explorer views.
 *
 * Until v0.3 the identifier `all` meant the rolling 12-month window (since #1017 `last_12_months`) and the
 * whole history was `all_time`. Since v0.4-beta (#1035) `all` is the whole history; old `all_time` links are
 * redirected by {@see \App\Statistics\UI\Http\LegacyAllTimePeriodRedirectSubscriber} until v1.0.
 */
enum StatisticsFilterPeriod: string
{
    /** Rolling 12-month window ending now (default of most statistics pages). */
    case Last12Months = 'last_12_months';

    /** Full history (no lower bound, upper bound is now). */
    case AllTime = 'all';

    case Year = 'year';
    case Quarter = 'quarter';
    case Month = 'month';
}
