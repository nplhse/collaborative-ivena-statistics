<?php

declare(strict_types=1);

namespace App\Content\Application\Event;

final class CommentCreated
{
    public function __construct(
        public int $userId,
        public int $commentId,
        public string $postTitle,
        public string $postSlug,
        public string $excerpt,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
