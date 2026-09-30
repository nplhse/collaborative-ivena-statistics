<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

final readonly class ClosureTimelineSegment
{
    public function __construct(
        public string $dayKey,
        public int $hospitalId,
        public string $hospitalName,
        public string $specialityName,
        public string $careLevelName,
        public string $departmentName,
        public string $eventKey,
        public ClosureEventType $eventType,
        public ?string $sourceGroupId,
        public int $intervalId,
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
        public bool $parallel,
    ) {
    }
}
