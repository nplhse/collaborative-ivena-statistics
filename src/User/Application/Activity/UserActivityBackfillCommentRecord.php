<?php

declare(strict_types=1);

namespace App\User\Application\Activity;

final readonly class UserActivityBackfillCommentRecord
{
    public function __construct(
        public int $userId,
        public int $commentId,
        public string $postTitle,
        public string $postSlug,
        public string $excerpt,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
