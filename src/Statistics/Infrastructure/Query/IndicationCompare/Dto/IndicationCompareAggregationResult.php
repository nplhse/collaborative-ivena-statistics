<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\IndicationCompare\Dto;

final readonly class IndicationCompareAggregationResult
{
    /**
     * @param list<IndicationCompareDistributionRow> $distributionRows
     */
    public function __construct(
        public IndicationCompareSideCounts $sideA,
        public IndicationCompareSideCounts $sideB,
        public array $distributionRows = [],
    ) {
    }

    public static function empty(): self
    {
        return new self(IndicationCompareSideCounts::empty(), IndicationCompareSideCounts::empty());
    }
}
