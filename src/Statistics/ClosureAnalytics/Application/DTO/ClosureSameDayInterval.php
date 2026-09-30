<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

final readonly class ClosureSameDayInterval
{
    public function __construct(
        public string $eventKey,
        public ClosureEventType $eventType,
        public string $departmentName,
        public string $specialityName,
        public string $careLevel,
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
    ) {
    }
}
