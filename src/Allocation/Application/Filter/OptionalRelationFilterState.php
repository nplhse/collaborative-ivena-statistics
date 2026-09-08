<?php

declare(strict_types=1);

namespace App\Allocation\Application\Filter;

enum OptionalRelationFilterState
{
    case Unset;
    case Absent;
    case Present;
    case Equals;
}
