<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

enum ClosureVolumeReferenceMode: string
{
    case WeekdayHour = 'weekday_hour';
    case DayTimeBucket = 'day_time_bucket';
    case Insufficient = 'insufficient';
}
