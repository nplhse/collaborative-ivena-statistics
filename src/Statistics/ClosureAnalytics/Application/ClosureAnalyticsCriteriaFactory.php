<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\Application\TimeSeries\TimeSeriesGrainResolver;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsFilter;
use App\User\Domain\Entity\User;

final readonly class ClosureAnalyticsCriteriaFactory
{
    public function __construct(
        private StatisticsContextFactory $contextFactory,
        private StatisticsScopeResolver $scopeResolver,
    ) {
    }

    public function create(
        ?User $user,
        StatisticsFilter $filter,
        ?ClosureAnalyticsFilter $closureFilter = null,
    ): ClosureAnalyticsCriteria {
        $context = $this->contextFactory->create($user, $filter);
        $closureFilter ??= ClosureAnalyticsFilter::empty();

        return new ClosureAnalyticsCriteria(
            $this->scopeResolver->resolveCriteria($context),
            StatisticsPeriodResolver::resolve($filter),
            TimeSeriesGrainResolver::resolve($filter->period),
            $filter,
            $closureFilter->departmentIds,
            $closureFilter->specialityIds,
            $closureFilter->careLevels,
            $closureFilter->reasons,
            \in_array($filter->scope, [StatisticsFilterScope::Hospital, StatisticsFilterScope::MyHospitals], true)
                ? $closureFilter->closureUnits
                : [],
        );
    }
}
