<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

final readonly class ClosureEventChildPreview
{
    public function __construct(
        public int $id,
        public string $specialityName,
        public string $departmentName,
        public string $careLevel,
        public string $reason,
        public ?string $closureUnit,
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
    ) {
    }
}
