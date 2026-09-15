<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\DTO;

use App\Statistics\Application\DTO\StatisticsDrawerFilter;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;

final readonly class CaseFlowCriteria
{
    public function __construct(
        public StatisticsFilter $filter,
        public StatisticsScopeCriteria $scope,
        public StatisticsPeriodBounds $period,
        public CaseFlowMode $mode,
        public ?StatisticsDrawerFilter $drawerFilter = null,
    ) {
    }

    public function originStateId(): ?int
    {
        return StatisticsFilterScope::State === $this->filter->scope ? $this->filter->stateId : null;
    }

    public function isSingleHospital(): bool
    {
        return StatisticsFilterScope::Hospital === $this->filter->scope && null !== $this->filter->hospitalId;
    }

    public function isDispatchAreaScope(): bool
    {
        return StatisticsFilterScope::DispatchArea === $this->filter->scope && null !== $this->filter->dispatchAreaId;
    }
}
