<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO;

enum StatisticsFilterNotice: string
{
    case CohortTooSmall = 'stats.filter.scope.cohort_too_small';
    case StateInvalid = 'stats.filter.scope.state_invalid';
    case DispatchAreaInvalid = 'stats.filter.scope.dispatch_area_invalid';
}
