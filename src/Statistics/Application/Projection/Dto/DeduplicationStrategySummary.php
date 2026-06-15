<?php

declare(strict_types=1);

namespace App\Statistics\Application\Projection\Dto;

final readonly class DeduplicationStrategySummary
{
    /**
     * @param list<int> $sampleAllocationIds
     */
    public function __construct(
        public string $strategy,
        public int $duplicateGroups,
        public int $duplicateRows,
        public array $sampleAllocationIds,
    ) {
    }
}
