<?php

declare(strict_types=1);

namespace App\Encounter\Application\Matching;

/**
 * Features of matcher v1 (ADR 029). Keys of the stored contributions.
 */
enum MatchFeature: string
{
    case Time = 'time';
    case Sex = 'sex';
    case Age = 'age';
    case ArrivalRoute = 'arrival_route';
    case Physician = 'physician';
    case WorkAccident = 'work_accident';
}
