<?php

declare(strict_types=1);

namespace App\User\Application\Activity;

final readonly class UserActivityBackfillImportRecord
{
    public function __construct(
        public int $userId,
        public int $hospitalId,
        public string $hospitalPublicId,
        public string $hospitalName,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
