<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\TimeSeries\TimeSeriesGrainResolver;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsFilter;
use App\User\Domain\Entity\User;

final readonly class ClosureAnalyticsCriteriaFactory
{
    public function __construct(
        private ClosureAnalyticsHospitalScope $hospitalScope,
    ) {
    }

    public function create(
        ?User $user,
        StatisticsFilter $filter,
        ?ClosureAnalyticsFilter $closureFilter = null,
    ): ClosureAnalyticsCriteria {
        $closureFilter ??= ClosureAnalyticsFilter::empty();
        $hospitalIds = $this->hospitalScope->effectiveHospitalIds($user, $filter, $closureFilter);

        return new ClosureAnalyticsCriteria(
            new StatisticsScopeCriteria($hospitalIds),
            StatisticsPeriodResolver::resolve($filter)->intersect(
                $closureFilter->periodFrom(),
                $closureFilter->periodToExclusive(),
            ),
            TimeSeriesGrainResolver::resolve($filter->period),
            $filter,
            $closureFilter->departmentIds,
            $closureFilter->specialityIds,
            $closureFilter->careLevels,
            $closureFilter->reasons,
            \in_array($filter->scope, [StatisticsFilterScope::Hospital, StatisticsFilterScope::MyHospitals], true)
                ? $closureFilter->closureUnits
                : [],
            $closureFilter->eventTypes,
            profile: $closureFilter->profile,
        );
    }
}
