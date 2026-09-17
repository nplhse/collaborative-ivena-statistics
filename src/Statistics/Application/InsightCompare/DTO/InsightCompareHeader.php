<?php

declare(strict_types=1);

namespace App\Statistics\Application\InsightCompare\DTO;

use App\Statistics\Application\Insights\InsightDimensionKey;

final readonly class InsightCompareHeader
{
    public function __construct(
        public InsightDimensionKey $dimensionA,
        public int $idA,
        public string $labelA,
        public string $dimensionLabelA,
        public string $filterLabelA,
        public InsightDimensionKey $dimensionB,
        public int $idB,
        public string $labelB,
        public string $dimensionLabelB,
        public string $filterLabelB,
        public int $totalA,
        public int $totalB,
    ) {
    }
}
