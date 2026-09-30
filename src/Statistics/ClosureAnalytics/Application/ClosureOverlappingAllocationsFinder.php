<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application;

use App\Statistics\ClosureAnalytics\Application\DTO\ClosureIntervalRow;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureOverlappingAllocationRow;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureOverlapWindow;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureOverlappingAllocationsQuery;

final readonly class ClosureOverlappingAllocationsFinder
{
    public function __construct(
        private ClosureOverlappingAllocationsQuery $query,
    ) {
    }

    /**
     * @param list<ClosureIntervalRow> $intervals
     *
     * @return list<ClosureOverlappingAllocationRow>
     */
    public function forIntervals(array $intervals): array
    {
        $windows = [];
        foreach ($intervals as $interval) {
            $windows[] = new ClosureOverlapWindow(
                $interval->hospitalId,
                $interval->departmentId,
                $interval->startsAt,
                $interval->endsAt,
            );
        }

        return $this->query->fetch($windows);
    }
}
