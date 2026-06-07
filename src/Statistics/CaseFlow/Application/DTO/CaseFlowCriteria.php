<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\DTO;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;

final readonly class CaseFlowCriteria
{
    public function __construct(
        public StatisticsScopeCriteria $scope,
        public StatisticsPeriodBounds $period,
        public CaseFlowMode $mode,
    ) {
    }
}
