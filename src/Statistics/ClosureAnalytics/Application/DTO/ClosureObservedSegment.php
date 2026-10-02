<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

/**
 * Estimated import coverage for one hospital, already clipped to the analysis window.
 */
final readonly class ClosureObservedSegment
{
    public function __construct(
        public int $hospitalId,
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
    ) {
    }
}
