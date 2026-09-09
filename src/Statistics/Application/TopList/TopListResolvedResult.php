<?php

declare(strict_types=1);

namespace App\Statistics\Application\TopList;

use App\Statistics\Application\DTO\StatisticsFilter;

final readonly class TopListResolvedResult
{
    public function __construct(
        public TopListRanking $rankingA,
        public StatisticsFilter $comparisonFilter,
        public ?TopListComparison $comparison,
    ) {
    }
}
