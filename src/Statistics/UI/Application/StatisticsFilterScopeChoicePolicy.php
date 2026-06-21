<?php

declare(strict_types=1);

namespace App\Statistics\UI\Application;

/**
 * Controls which states/dispatch areas appear as scope choices in filter forms.
 */
enum StatisticsFilterScopeChoicePolicy: string
{
    /** States/areas with at least two registered hospitals (materialized overview views). */
    case RegisteredHospitals = 'registered_hospitals';

    /** States/areas with allocation statistics from at least two hospitals. */
    case AllocationStatistics = 'allocation_statistics';
}
