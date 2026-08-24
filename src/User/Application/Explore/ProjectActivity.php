<?php

declare(strict_types=1);

namespace App\User\Application\Explore;

/** @psalm-suppress PossiblyUnusedProperty Consumed by Twig dashboard templates. */
final readonly class ProjectActivity
{
    public function __construct(
        public \DateTimeImmutable $occurredAt,
        public ProfileActivityType $type,
        public string $stableId,
        public string $actorUsername,
        public ?string $actorPublicId = null,
        public ?string $hospitalName = null,
        public ?string $hospitalPublicId = null,
        public ?int $milestone = null,
        public ?string $postTitle = null,
        public ?string $postSlug = null,
        public ?string $excerpt = null,
    ) {
    }
}
