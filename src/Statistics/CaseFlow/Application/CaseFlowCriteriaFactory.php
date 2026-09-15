<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsDrawerFilterFactory;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowCriteria;
use App\User\Domain\Entity\User;
use Symfony\Component\HttpFoundation\Request;

final readonly class CaseFlowCriteriaFactory
{
    public function __construct(
        private StatisticsContextFactory $statisticsContextFactory,
        private StatisticsScopeResolver $statisticsScopeResolver,
        private CaseFlowModeResolver $modeResolver,
        private StatisticsDrawerFilterFactory $statisticsDrawerFilterFactory,
    ) {
    }

    public function create(?User $user, StatisticsFilter $filter, Request $request): CaseFlowCriteria
    {
        $drawerFilter = $this->statisticsDrawerFilterFactory->fromRequest($request);
        $context = $this->statisticsContextFactory->create($user, $filter, drawerFilter: $drawerFilter);

        return new CaseFlowCriteria(
            $filter,
            $this->statisticsScopeResolver->resolveCriteria($context),
            StatisticsPeriodResolver::resolve($filter),
            $this->modeResolver->resolve($filter),
            $drawerFilter,
        );
    }
}
