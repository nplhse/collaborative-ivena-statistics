<?php

declare(strict_types=1);

namespace App\User\Application\Explore;

/** @psalm-suppress PossiblyUnusedProperty Consumed by Twig explore templates. */
final readonly class ProfileActivity
{
    public function __construct(
        public \DateTimeImmutable $occurredAt,
        public ProfileActivityType $type,
        public string $stableId,
        public ?string $hospitalName = null,
        public ?string $hospitalPublicId = null,
        public ?int $milestone = null,
        public ?string $postTitle = null,
        public ?string $postSlug = null,
        public ?string $excerpt = null,
    ) {
    }
}
