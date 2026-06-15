<?php

declare(strict_types=1);

namespace App\Statistics\Application\Projection\Dto;

final readonly class DeduplicationReport
{
    public function __construct(
        public DeduplicationStrategySummary $enr,
        public DeduplicationStrategySummary $fingerprint,
        public int $orphanProjectionRows,
        public int $enrHashGroupsSpanningMultipleYears = 0,
    ) {
    }

    public function totalDuplicateRows(): int
    {
        return $this->enr->duplicateRows + $this->fingerprint->duplicateRows;
    }
}
