<?php

declare(strict_types=1);

namespace App\Allocation\Application\Event;

final class HospitalOwnershipRevoked
{
    public function __construct(
        public int $userId,
        public int $hospitalId,
        public string $hospitalPublicId,
        public string $hospitalName,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
