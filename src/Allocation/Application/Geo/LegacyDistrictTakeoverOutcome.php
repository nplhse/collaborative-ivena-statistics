<?php

declare(strict_types=1);

namespace App\Allocation\Application\Geo;

enum LegacyDistrictTakeoverOutcome
{
    case Done;
    case AlreadyComplete;
    case NothingToTakeOver;
    case Failed;
}
