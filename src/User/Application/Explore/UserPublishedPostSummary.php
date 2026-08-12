<?php

declare(strict_types=1);

namespace App\User\Application\Explore;

/** @psalm-suppress PossiblyUnusedProperty Consumed by Twig explore templates. */
final readonly class UserPublishedPostSummary
{
    public function __construct(
        public string $title,
        public string $slug,
        public \DateTimeImmutable $publishedAt,
    ) {
    }
}
