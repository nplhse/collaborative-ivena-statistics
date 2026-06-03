<?php

declare(strict_types=1);

namespace App\Statistics\Application\Cohort;

final class HospitalCohortResolver
{
    public function resolve(HospitalCohortKey $key): HospitalCohort
    {
        return new HospitalCohort(
            $key,
            [$key->locationProjectionCode()],
            [$key->tierProjectionCode()],
        );
    }
}
