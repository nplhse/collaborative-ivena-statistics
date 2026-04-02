<?php

declare(strict_types=1);

namespace App\Statistics\Application\Panel\Distribution;

enum DimensionKind: string
{
    case Column = 'column';

    case AgeCohort = 'age_cohort';
}
