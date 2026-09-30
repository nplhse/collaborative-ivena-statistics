<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

final readonly class ClosureIntervalTableRow
{
    public function __construct(
        public int $id,
        public string $eventKey,
        public ClosureEventType $eventType,
        public string $hospitalName,
        public string $specialityName,
        public string $departmentName,
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
        public string $careLevel,
        public string $reason,
        public ?string $closureUnit,
        public ?string $sourceGroupId,
        public int $durationMinutes,
    ) {
    }

    public function grouped(): bool
    {
        return ClosureEventType::Group === $this->eventType;
    }

    public function clustered(): bool
    {
        return ClosureEventType::Cluster === $this->eventType;
    }
}
