<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

final readonly class ClosureDayTimelineLane
{
    /**
     * @param list<ClosureDayTimelineSegment> $segments
     */
    public function __construct(
        public string $departmentName,
        public string $specialityName,
        public string $careLevel,
        public array $segments,
    ) {
    }
}
