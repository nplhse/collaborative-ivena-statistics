<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Enum;

enum DistrictBoundaryFetchFailure: string
{
    case NotFound = 'not_found';
    case Unavailable = 'unavailable';
    case RateLimited = 'rate_limited';
    case InvalidGeometry = 'invalid_geometry';
    case DispatchFailed = 'dispatch_failed';
    case TimedOut = 'timed_out';
}
