<?php

declare(strict_types=1);

namespace App\Statistics\Application\Cohort;

use App\Statistics\Infrastructure\Query\AllocationStatsProjectionScopeQuery;

final readonly class HospitalCohortEligibilityChecker
{
    public function __construct(
        private AllocationStatsProjectionScopeQuery $projectionScopeQuery,
    ) {
    }

    public function hasMinimumParticipants(HospitalCohort $cohort, int $minimumParticipants = 2): bool
    {
        return $this->projectionScopeQuery->countDistinctHospitalsForCohort($cohort) >= $minimumParticipants;
    }
}
