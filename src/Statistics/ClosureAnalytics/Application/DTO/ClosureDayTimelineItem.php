<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

final readonly class ClosureDayTimelineItem
{
    public function __construct(
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
        public string $departmentName,
        public string $specialityName,
        public string $careLevel,
        public string $url,
        public string $title,
        public bool $primary = true,
        public ClosureEventType $eventType = ClosureEventType::Single,
    ) {
    }
}
