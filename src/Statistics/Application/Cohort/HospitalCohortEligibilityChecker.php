<?php

declare(strict_types=1);

namespace App\Statistics\Application\Cohort;

use App\Statistics\Infrastructure\Query\Overview\CountDistinctHospitalsByCohortQuery;

final class HospitalCohortEligibilityChecker
{
    /** @var array<string, bool> */
    private array $memo = [];

    public function __construct(
        private readonly CountDistinctHospitalsByCohortQuery $countDistinctHospitalsByCohortQuery,
    ) {
    }

    public function hasMinimumParticipants(HospitalCohort $cohort, int $minimumParticipants = 2): bool
    {
        $cacheKey = $cohort->key->value().'|'.$minimumParticipants;
        if (\array_key_exists($cacheKey, $this->memo)) {
            return $this->memo[$cacheKey];
        }

        $eligible = ($this->countDistinctHospitalsByCohortQuery)($cohort) >= $minimumParticipants;
        $this->memo[$cacheKey] = $eligible;

        return $eligible;
    }
}
