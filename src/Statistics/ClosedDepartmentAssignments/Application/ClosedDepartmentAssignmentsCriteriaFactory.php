<?php

declare(strict_types=1);

namespace App\Statistics\ClosedDepartmentAssignments\Application;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\Application\TimeSeries\TimeSeriesGrainResolver;
use App\Statistics\ClosedDepartmentAssignments\Application\DTO\ClosedDepartmentAssignmentsCriteria;
use App\User\Domain\Entity\User;

final readonly class ClosedDepartmentAssignmentsCriteriaFactory
{
    public function __construct(
        private StatisticsContextFactory $statisticsContextFactory,
        private StatisticsScopeResolver $statisticsScopeResolver,
    ) {
    }

    public function create(?User $user, StatisticsFilter $filter, bool $canExplore): ClosedDepartmentAssignmentsCriteria
    {
        $context = $this->statisticsContextFactory->create($user, $filter);
        $scope = $this->statisticsScopeResolver->resolveCriteria($context);
        $period = StatisticsPeriodResolver::resolve($filter);

        return new ClosedDepartmentAssignmentsCriteria(
            $scope,
            $period,
            TimeSeriesGrainResolver::resolve($filter->period),
            $filter,
            $canExplore,
        );
    }
}
