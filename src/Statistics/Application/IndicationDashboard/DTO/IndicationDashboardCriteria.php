<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationDashboard\DTO;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;

final readonly class IndicationDashboardCriteria
{
    public function __construct(
        public int $indicationId,
        public StatisticsScopeCriteria $scope,
        public StatisticsPeriodBounds $period,
    ) {
    }
}
