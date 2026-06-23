<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\Enum;

enum HospitalMetricClass: string
{
    case Structural = 'structural';
    case AllocationDerived = 'allocation_derived';
}
