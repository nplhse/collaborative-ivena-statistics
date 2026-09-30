<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

final readonly class ClosureDayTimelineSegment
{
    public function __construct(
        public float $left,
        public float $width,
        public string $url,
        public string $title,
        public bool $primary = true,
        public ClosureEventType $eventType = ClosureEventType::Single,
    ) {
    }
}
