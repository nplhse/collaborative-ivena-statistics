<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO;

use App\Statistics\Application\Cohort\HospitalCohortKey;

final readonly class StatisticsScopeCriteria
{
    /**
     * @param list<int>|null $hospitalIds
     * @param list<int>|null $locationCodes
     * @param list<int>|null $tierCodes
     */
    public function __construct(
        public ?array $hospitalIds,
        public ?array $locationCodes = null,
        public ?array $tierCodes = null,
        public ?HospitalCohortKey $cohortType = null,
    ) {
    }

    public static function public(): self
    {
        return new self(null);
    }
}
