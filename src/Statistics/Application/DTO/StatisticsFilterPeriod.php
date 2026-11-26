<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO;

/**
 * Period identifiers used in URLs (`period`, `comparison_period`), forms and saved explorer views.
 *
 * Until v0.3 the identifier `all` meant the rolling 12-month window. Since #1017 that window is
 * `last_12_months`; old `period=all` links are redirected by
 * {@see \App\Statistics\UI\Http\LegacyAllPeriodRedirectSubscriber} during the v0.3 transition.
 * Once that redirect is removed (v0.4-beta), `all` becomes the value of {@see self::AllTime}.
 */
enum StatisticsFilterPeriod: string
{
    /** Rolling 12-month window ending now (default of most statistics pages). */
    case Last12Months = 'last_12_months';

    /** Full history (no lower bound, upper bound is now). */
    case AllTime = 'all_time';

    case Year = 'year';
    case Quarter = 'quarter';
    case Month = 'month';
}
