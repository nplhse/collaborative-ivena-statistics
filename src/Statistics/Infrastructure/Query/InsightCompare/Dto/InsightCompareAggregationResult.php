<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\InsightCompare\Dto;

final readonly class InsightCompareAggregationResult
{
    /**
     * @param list<InsightCompareDistributionRow> $distributionRows
     */
    public function __construct(
        public InsightCompareSideCounts $sideA,
        public InsightCompareSideCounts $sideB,
        public array $distributionRows = [],
    ) {
    }

    public static function empty(): self
    {
        return new self(InsightCompareSideCounts::empty(), InsightCompareSideCounts::empty());
    }
}
