<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

final readonly class ClosureVolumeReferenceConfig
{
    public const int CONTEXT_HOURS = 6;

    /** Hours inside the closure that the course charts show after its start and before its end. */
    public const int INNER_FOCUS_HOURS = 3;

    /** @var list<int> */
    public const array WINDOW_HOURS = [1, 3, 6];

    public ClosureVolumeReferenceWindow $window;

    public function __construct(
        int $referenceWeeksBefore = 4,
        int $referenceWeeksAfter = 4,
        public int $minimumReferenceSlots = 4,
    ) {
        $this->window = new ClosureVolumeReferenceWindow($referenceWeeksBefore, $referenceWeeksAfter);
        if ($this->minimumReferenceSlots < 1) {
            throw new \InvalidArgumentException('Minimum reference slots must be positive.');
        }
    }
}
