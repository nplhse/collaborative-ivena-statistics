<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO;

enum StatisticsFilterNotice: string
{
    case CohortTooSmall = 'stats.filter.scope.cohort_too_small';
}
