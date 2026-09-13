<?php

declare(strict_types=1);

namespace App\Statistics\ClosedDepartmentAssignments\Application\DTO;

final readonly class ClosedDepartmentTimeSeries
{
    /**
     * @param list<string> $labels
     * @param list<int>    $counts
     * @param list<?float> $shares
     */
    public function __construct(
        public array $labels,
        public array $counts,
        public array $shares,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->labels;
    }
}
