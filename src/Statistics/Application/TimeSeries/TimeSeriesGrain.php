<?php

declare(strict_types=1);

namespace App\Statistics\Application\TimeSeries;

enum TimeSeriesGrain: string
{
    case Day = 'day';
    case Month = 'month';
}
