<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

final readonly class ClosureTimelineGridCell
{
    public function __construct(
        public string $key,
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
        public int $closedMinutes,
        public int $closureCount,
        public int $eventCount,
    ) {
    }
}
