<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\Enum;

enum HospitalPopulationMode: string
{
    case All = 'all';
    case Participating = 'participating';
    case Compare = 'compare';
}
