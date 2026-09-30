<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

final readonly class ClosureDayTimelineDay
{
    /**
     * @param list<array{label: string, position: float}> $ticks
     * @param list<ClosureDayTimelineLane>                $lanes
     */
    public function __construct(
        public string $dayKey,
        public array $ticks,
        public array $lanes,
    ) {
    }
}
