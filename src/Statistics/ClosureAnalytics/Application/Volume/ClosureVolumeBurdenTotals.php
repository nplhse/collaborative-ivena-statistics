<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

final readonly class ClosureVolumeBurdenTotals
{
    public function __construct(
        public ?float $observedArea,
        public ?float $expectedArea,
        public ?float $absoluteDeviation,
        public ?float $relativeDeviation,
        public bool $partialReference,
        public bool $partialObservation,
        public int $sliceCount,
    ) {
    }
}
