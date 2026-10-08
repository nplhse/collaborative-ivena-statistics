<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

enum ClosureVolumeQuality: string
{
    case Reliable = 'reliable';
    case Limited = 'limited';
    case Insufficient = 'insufficient';
    case Incomplete = 'incomplete';
    case Influenced = 'influenced';
    case Ongoing = 'ongoing';
    case NotApplicable = 'not_applicable';
}
