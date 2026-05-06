<?php

declare(strict_types=1);

namespace App\Statistics\Application\Cohort;

enum HospitalCohortType: string
{
    case UrbanBasic = 'urban_basic';
    case UrbanAdvanced = 'urban_advanced';
    case RuralBasic = 'rural_basic';
    case RuralMaximum = 'rural_maximum';
}
