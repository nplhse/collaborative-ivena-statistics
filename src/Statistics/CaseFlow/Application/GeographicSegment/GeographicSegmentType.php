<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\GeographicSegment;

enum GeographicSegmentType: string
{
    case OriginArea = 'origin_area';
    case TravelTimeBand = 'travel_time_band';
}
