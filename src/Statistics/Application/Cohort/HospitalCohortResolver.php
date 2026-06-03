<?php

declare(strict_types=1);

namespace App\Statistics\Application\Cohort;

use App\Statistics\Application\Mapping\AllocationStatsHospitalLocationProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsHospitalTierProjectionCode;

final class HospitalCohortResolver
{
    public function resolve(HospitalCohortKey $key): HospitalCohort
    {
        $locationCode = AllocationStatsHospitalLocationProjectionCode::tryFromHospitalLocation($key->location);
        $tierCode = AllocationStatsHospitalTierProjectionCode::tryFromHospitalTier($key->tier);

        if (!$locationCode instanceof AllocationStatsHospitalLocationProjectionCode
            || !$tierCode instanceof AllocationStatsHospitalTierProjectionCode) {
            throw new \InvalidArgumentException(sprintf('Unsupported cohort key "%s".', $key->value()));
        }

        return new HospitalCohort(
            $key,
            [$locationCode],
            [$tierCode],
        );
    }
}
