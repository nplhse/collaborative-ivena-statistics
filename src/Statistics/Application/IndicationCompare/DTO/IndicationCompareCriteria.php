<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationCompare\DTO;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;

final readonly class IndicationCompareCriteria
{
    public function __construct(
        public int $indicationIdA,
        public int $indicationIdB,
        public string $indicationLabelA,
        public string $indicationLabelB,
        public StatisticsScopeCriteria $scope,
        public StatisticsPeriodBounds $period,
    ) {
    }
}
