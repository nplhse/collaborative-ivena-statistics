<?php

declare(strict_types=1);

namespace App\Encounter\Application\Matching;

/**
 * Features of the matcher (ADR 030). Keys of the stored contributions. `plausibility` (PZC × MTS diagram bonus) exists
 * since matcher 1.2.0 and only in pass 2 (ADR 030 amendment, ADR 033); {@see FeatureScorer} scores the others.
 */
enum MatchFeature: string
{
    case Time = 'time';
    case Sex = 'sex';
    case Age = 'age';
    case ArrivalRoute = 'arrival_route';
    case Physician = 'physician';
    case WorkAccident = 'work_accident';
    case Plausibility = 'plausibility';
}
