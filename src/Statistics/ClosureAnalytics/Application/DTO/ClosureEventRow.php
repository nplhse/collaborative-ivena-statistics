<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

final readonly class ClosureEventRow
{
    public function __construct(
        public string $key,
        public int $hospitalId,
        public string $hospitalName,
        public ?string $sourceGroupId,
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
        public int $closureCount,
        public int $summedMinutes,
        public int $actualMinutes,
        public int $observedMinutes,
    ) {
    }

    public function grouped(): bool
    {
        return null !== $this->sourceGroupId;
    }
}
