<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital;

final readonly class HospitalAssociationSnapshot
{
    public function __construct(
        public int $userId,
        public int $hospitalId,
        public int $grantId,
        public string $hospitalPublicId,
        public string $hospitalName,
        public \DateTimeImmutable $grantedAt,
    ) {
    }
}
