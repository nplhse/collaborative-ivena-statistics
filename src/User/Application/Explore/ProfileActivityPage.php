<?php

declare(strict_types=1);

namespace App\User\Application\Explore;

/** @psalm-suppress PossiblyUnusedProperty Consumed by Twig explore templates. */
final readonly class ProfileActivityPage
{
    public const int PAGE_SIZE = 20;

    /**
     * @param list<ProfileActivity> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public int $pageSize = self::PAGE_SIZE,
    ) {
    }

    /** @psalm-suppress PossiblyUnusedMethod Consumed by Twig explore templates. */
    public function hasMore(): bool
    {
        return null !== $this->nextCursor;
    }

    /** @psalm-suppress PossiblyUnusedMethod Consumed by Twig explore templates. */
    public function nextFrameId(): ?string
    {
        if (null === $this->nextCursor) {
            return null;
        }

        return ProfileActivityCursor::frameId($this->nextCursor);
    }
}
