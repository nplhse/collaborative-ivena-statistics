<?php

declare(strict_types=1);

namespace App\Statistics\Application\Cohort;

use App\Statistics\Application\Mapping\AllocationStatsHospitalLocationProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsHospitalTierProjectionCode;

final readonly class HospitalCohort
{
    /**
     * @param non-empty-list<AllocationStatsHospitalLocationProjectionCode> $locationCodes
     * @param non-empty-list<AllocationStatsHospitalTierProjectionCode>     $tierCodes
     */
    public function __construct(
        public HospitalCohortType $type,
        public array $locationCodes,
        public array $tierCodes,
    ) {
    }

    /**
     * @return list<int>
     */
    public function locationCodeValues(): array
    {
        return array_map(
            static fn (AllocationStatsHospitalLocationProjectionCode $code): int => $code->value,
            $this->locationCodes,
        );
    }

    /**
     * @return list<int>
     */
    public function tierCodeValues(): array
    {
        return array_map(
            static fn (AllocationStatsHospitalTierProjectionCode $code): int => $code->value,
            $this->tierCodes,
        );
    }
}
