<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO;

enum StatisticsFilterPeriod: string
{
    /** Same rolling 12-month window as the overview (not a full lifetime window). */
    case All = 'all';

    /** Full history (lower bound effectively very early, upper bound is now). */
    case AllTime = 'all_time';

    case Year = 'year';
    case Month = 'month';
}
