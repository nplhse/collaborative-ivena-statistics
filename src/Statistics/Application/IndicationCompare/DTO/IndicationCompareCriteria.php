<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationCompare\DTO;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\Insights\InsightSubject;

final readonly class IndicationCompareCriteria
{
    public function __construct(
        public InsightSubject $subjectA,
        public InsightSubject $subjectB,
        public StatisticsScopeCriteria $scope,
        public StatisticsPeriodBounds $period,
    ) {
    }
}
