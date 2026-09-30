<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

final readonly class ClosureTimeMetrics
{
    public function __construct(
        public int $closureCount,
        public int $eventCount,
        public int $departmentCount,
        public int $summedMinutes,
        public int $observedMinutes,
        public int $calendarMinutes,
        public int $closedMinutes,
        public int $singleMinutes,
        public int $multipleMinutes,
    ) {
    }
}
