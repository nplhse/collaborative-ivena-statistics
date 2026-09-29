<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\TimeSeries\TimeSeriesGrain;

final readonly class ClosureAnalyticsCriteria
{
    /**
     * @param list<int>    $departmentIds
     * @param list<int>    $specialityIds
     * @param list<string> $careLevels
     * @param list<string> $reasons
     * @param list<string> $closureUnits
     */
    public function __construct(
        public StatisticsScopeCriteria $scope,
        public StatisticsPeriodBounds $period,
        public TimeSeriesGrain $timeSeriesGrain,
        public StatisticsFilter $filter,
        public array $departmentIds = [],
        public array $specialityIds = [],
        public array $careLevels = [],
        public array $reasons = [],
        public array $closureUnits = [],
    ) {
    }
}
