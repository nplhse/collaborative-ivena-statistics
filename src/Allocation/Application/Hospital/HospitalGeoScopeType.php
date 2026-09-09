<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital;

enum HospitalGeoScopeType
{
    case Hospital;
    case DispatchArea;
    case State;
}
