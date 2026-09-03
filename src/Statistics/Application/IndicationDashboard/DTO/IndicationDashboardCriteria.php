<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationDashboard\DTO;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\TimeSeries\TimeSeriesGrain;

final readonly class IndicationDashboardCriteria
{
    public function __construct(
        public int $indicationId,
        public StatisticsScopeCriteria $scope,
        public StatisticsPeriodBounds $period,
        public TimeSeriesGrain $timeSeriesGrain = TimeSeriesGrain::Month,
    ) {
    }
}
