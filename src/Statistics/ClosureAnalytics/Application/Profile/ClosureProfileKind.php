<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Profile;

enum ClosureProfileKind: string
{
    case Hospital = 'hospital';
    case Speciality = 'speciality';
    case Department = 'department';
    case ClosureUnit = 'closure_unit';
    case Group = 'group';
}
