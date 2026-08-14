<?php

declare(strict_types=1);

namespace App\User\Application\Activity;

final readonly class UserActivityBackfillPostRecord
{
    public function __construct(
        public int $userId,
        public int $postId,
        public string $title,
        public string $slug,
        public \DateTimeImmutable $publishedAt,
    ) {
    }
}
