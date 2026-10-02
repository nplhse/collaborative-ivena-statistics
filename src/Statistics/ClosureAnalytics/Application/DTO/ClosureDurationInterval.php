<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

/**
 * One already clipped closure row. Duration math uses absolute instants.
 */
final readonly class ClosureDurationInterval
{
    public function __construct(
        public int $hospitalId,
        public int $departmentId,
        public string $departmentName,
        public ?string $reason,
        public string $eventKey,
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
        public int $specialityId = 0,
        public string $specialityName = '',
    ) {
    }
}
