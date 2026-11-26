<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

enum AnalysisContextScopeMode: string
{
    case Standard = 'standard';
    case AssignedHospitals = 'assigned_hospitals';

    /**
     * Exactly one of the hospitals the caller passes (no "my hospitals" aggregate), for analyses that
     * must never add up hospitals, e.g. the encounter data overview (#1055).
     */
    case AssignedHospital = 'assigned_hospital';

    public function isAssigned(): bool
    {
        return self::Standard !== $this;
    }
}
