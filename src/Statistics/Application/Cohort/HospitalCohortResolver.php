<?php

declare(strict_types=1);

namespace App\Statistics\Application\Cohort;

use App\Statistics\Application\Mapping\AllocationStatsHospitalLocationProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsHospitalTierProjectionCode;

final class HospitalCohortResolver
{
    public function resolve(HospitalCohortType $type): HospitalCohort
    {
        return match ($type) {
            HospitalCohortType::UrbanBasic => new HospitalCohort(
                $type,
                [AllocationStatsHospitalLocationProjectionCode::Urban],
                [AllocationStatsHospitalTierProjectionCode::Basic],
            ),
            HospitalCohortType::UrbanAdvanced => new HospitalCohort(
                $type,
                [AllocationStatsHospitalLocationProjectionCode::Urban],
                [AllocationStatsHospitalTierProjectionCode::Extended, AllocationStatsHospitalTierProjectionCode::Full],
            ),
            HospitalCohortType::RuralBasic => new HospitalCohort(
                $type,
                [AllocationStatsHospitalLocationProjectionCode::Rural],
                [AllocationStatsHospitalTierProjectionCode::Basic],
            ),
            HospitalCohortType::RuralMaximum => new HospitalCohort(
                $type,
                [AllocationStatsHospitalLocationProjectionCode::Rural],
                [AllocationStatsHospitalTierProjectionCode::Full],
            ),
        };
    }
}
