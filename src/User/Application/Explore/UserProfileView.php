<?php

declare(strict_types=1);

namespace App\User\Application\Explore;

/** @psalm-suppress PossiblyUnusedProperty Consumed by Twig explore templates. */
final readonly class UserProfileView
{
    /**
     * @param list<UserHospitalSummary>      $hospitals
     * @param list<UserPublishedPostSummary> $posts
     */
    public function __construct(
        public string $publicId,
        public string $username,
        public bool $isAdmin,
        public bool $isParticipant,
        public bool $isBoardMember,
        public bool $isSelf,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $updatedAt,
        public array $hospitals,
        public int $successfulImportCount,
        public ?\DateTimeImmutable $lastSuccessfulImportAt,
        public array $posts,
    ) {
    }
}
