<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Profile;

final readonly class ClosureProfileCoursePoint
{
    public function __construct(
        public int $offset,
        public int $eventCount,
        public ?float $observedMedian,
        public ?float $observedQ1,
        public ?float $observedQ3,
        public ?float $observedMean,
        public ?float $expectedMedian,
        public ?float $expectedMean,
        public float $reliableShare,
        public bool $influenced,
        public bool $memberBoundary,
        public bool $lineSuppressed,
        public float $closureShare,
    ) {
    }
}
