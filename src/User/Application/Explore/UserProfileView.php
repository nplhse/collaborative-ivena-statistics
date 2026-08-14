<?php

declare(strict_types=1);

namespace App\User\Application\Explore;

/** @psalm-suppress PossiblyUnusedProperty Consumed by Twig explore templates. */
final readonly class UserProfileView
{
    /**
     * @param list<UserHospitalSummary> $hospitals
     * @param list<ProfileActivity>     $activities
     */
    public function __construct(
        public string $publicId,
        public string $username,
        public bool $isAdmin,
        public bool $isParticipant,
        public bool $isBoardMember,
        public bool $isSelf,
        public \DateTimeImmutable $createdAt,
        public array $hospitals,
        public int $successfulImportCount,
        public int $publishedPostCount,
        public int $commentCount,
        public array $activities,
        public ?string $activityNextCursor,
    ) {
    }

    /** @psalm-suppress PossiblyUnusedMethod Consumed by Twig explore templates. */
    public function hasMoreActivities(): bool
    {
        return null !== $this->activityNextCursor;
    }

    /** @psalm-suppress PossiblyUnusedMethod Consumed by Twig explore templates. */
    public function activityNextFrameId(): ?string
    {
        if (null === $this->activityNextCursor) {
            return null;
        }

        return ProfileActivityCursor::frameId($this->activityNextCursor);
    }
}
