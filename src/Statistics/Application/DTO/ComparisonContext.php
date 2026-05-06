<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO;

final readonly class ComparisonContext
{
    public function __construct(
        public StatisticsFilter $primaryFilter,
        public StatisticsFilter $comparisonFilter,
    ) {
    }
}
