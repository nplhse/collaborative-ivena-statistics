<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

enum AnalysisContextScopeMode: string
{
    case Standard = 'standard';
    case AssignedHospitals = 'assigned_hospitals';
}
