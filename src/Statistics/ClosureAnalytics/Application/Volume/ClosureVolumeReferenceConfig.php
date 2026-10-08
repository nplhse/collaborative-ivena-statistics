<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

final readonly class ClosureVolumeReferenceConfig
{
    public const int CONTEXT_HOURS = 6;

    /** @var list<int> */
    public const array WINDOW_HOURS = [1, 3, 6];

    public function __construct(
        public int $referenceWeeks = 8,
        public int $minimumReferenceSlots = 4,
    ) {
        if ($this->referenceWeeks < 1) {
            throw new \InvalidArgumentException('Reference weeks must be positive.');
        }
        if ($this->minimumReferenceSlots < 1) {
            throw new \InvalidArgumentException('Minimum reference slots must be positive.');
        }
    }
}
