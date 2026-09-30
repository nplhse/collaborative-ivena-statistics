<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

final readonly class ClosureHeatmapCell
{
    public function __construct(
        public int $weekday,
        public int $twoHourSlot,
        public int $observedMinutes,
        public int $closedMinutes,
    ) {
    }
}
