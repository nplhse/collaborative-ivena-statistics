<?php

declare(strict_types=1);

namespace App\Statistics\Application\InsightCompare\DTO;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\Insights\InsightSubject;

final readonly class InsightCompareCriteria
{
    public function __construct(
        public InsightSubject $subjectA,
        public InsightSubject $subjectB,
        public StatisticsScopeCriteria $scopeA,
        public StatisticsPeriodBounds $periodA,
        public StatisticsScopeCriteria $scopeB,
        public StatisticsPeriodBounds $periodB,
    ) {
    }
}
