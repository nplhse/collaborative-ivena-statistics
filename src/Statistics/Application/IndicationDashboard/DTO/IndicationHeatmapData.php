<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationDashboard\DTO;

final readonly class IndicationHeatmapData
{
    /**
     * @param list<string>    $rowLabels    Mon–Sun
     * @param list<string>    $columnLabels Day-time buckets
     * @param list<list<int>> $matrix       [weekdayIndex][bucketIndex]
     * @param list<string>    $rowKeys      ISO weekday keys for matrix rows
     */
    public function __construct(
        public array $rowLabels,
        public array $columnLabels,
        public array $matrix,
        public int $maxCount,
        public array $rowKeys = [],
    ) {
    }
}
