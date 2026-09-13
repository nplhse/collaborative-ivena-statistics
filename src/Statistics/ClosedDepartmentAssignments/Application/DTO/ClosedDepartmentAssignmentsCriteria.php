<?php

declare(strict_types=1);

namespace App\Statistics\ClosedDepartmentAssignments\Application\DTO;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\TimeSeries\TimeSeriesGrain;

final readonly class ClosedDepartmentAssignmentsCriteria
{
    public function __construct(
        public StatisticsScopeCriteria $scope,
        public StatisticsPeriodBounds $period,
        public TimeSeriesGrain $timeSeriesGrain,
        public StatisticsFilter $filter,
        public bool $canExplore,
    ) {
    }
}
