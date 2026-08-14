<?php

declare(strict_types=1);

namespace App\Content\Application\Event;

final class PostPublished
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
