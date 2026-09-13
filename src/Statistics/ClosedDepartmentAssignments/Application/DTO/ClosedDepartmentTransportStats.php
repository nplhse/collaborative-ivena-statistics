<?php

declare(strict_types=1);

namespace App\Statistics\ClosedDepartmentAssignments\Application\DTO;

final readonly class ClosedDepartmentTransportStats
{
    /**
     * @param list<ClosedDepartmentDistributionRow> $closedBuckets
     * @param list<ClosedDepartmentDistributionRow> $regularBuckets
     * @param list<ClosedDepartmentDistributionRow> $totalBuckets
     */
    public function __construct(
        public ?float $closedMeanMinutes,
        public ?float $regularMeanMinutes,
        public ?float $meanDeltaMinutes,
        public array $closedBuckets,
        public array $regularBuckets,
        public array $totalBuckets,
    ) {
    }

    public static function empty(): self
    {
        return new self(null, null, null, [], [], []);
    }
}
