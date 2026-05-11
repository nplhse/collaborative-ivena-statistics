<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO;

use App\Statistics\Application\Cohort\HospitalCohortType;

/**
 * URL-driven statistics filter (immutable).
 *
 * - hospitalId: set only for {@see StatisticsFilterScope::Hospital} and > 0.
 * - stateId / dispatchAreaId: set only for the matching regional scope.
 * - referenceYear / referenceMonth: anchor for {@see StatisticsFilterPeriod::Year} or Month (month 1–12).
 * - {@see StatisticsFilterPeriod::AllTime}: no reference year; aggregation over full history.
 */
final readonly class StatisticsFilter
{
    public function __construct(
        public StatisticsFilterScope $scope,
        public ?int $hospitalId,
        public ?HospitalCohortType $cohortType,
        public StatisticsFilterPeriod $period,
        public ?int $referenceYear = null,
        public ?int $referenceMonth = null,
        public ?StatisticsFilterNotice $notice = null,
        public bool $requiresPublicRedirect = false,
        public ?int $stateId = null,
        public ?int $dispatchAreaId = null,
    ) {
    }
}
