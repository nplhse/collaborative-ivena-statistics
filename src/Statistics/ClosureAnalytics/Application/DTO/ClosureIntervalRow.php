<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

final readonly class ClosureIntervalRow
{
    public function __construct(
        public int $id,
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
        public ?string $facilityKind = null,
        public ?\DateTimeImmutable $sourceRecordedAt = null,
        public ?\DateTimeImmutable $sourceChangedAt = null,
    ) {
    }
}
