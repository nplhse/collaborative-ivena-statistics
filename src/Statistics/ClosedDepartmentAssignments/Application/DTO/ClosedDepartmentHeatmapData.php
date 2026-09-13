<?php

declare(strict_types=1);

namespace App\Statistics\ClosedDepartmentAssignments\Application\DTO;

final readonly class ClosedDepartmentHeatmapData
{
    /**
     * @param list<string>    $rowLabels    Mon–Sun
     * @param list<string>    $columnLabels 12 two-hour slots 00–02 … 22–24
     * @param list<list<int>> $matrix       [weekdayIndex][twoHourSlot]
     */
    public function __construct(
        public array $rowLabels,
        public array $columnLabels,
        public array $matrix,
        public int $maxCount,
    ) {
    }

    public function isEmpty(): bool
    {
        return 0 === $this->maxCount;
    }
}
