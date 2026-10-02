<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

final readonly class ClosureDurationLoadSnapshot
{
    /**
     * @param list<ClosureDurationInterval> $intervals
     * @param list<ClosureObservedSegment>  $observed
     */
    public function __construct(
        public array $intervals,
        public array $observed,
    ) {
    }
}
