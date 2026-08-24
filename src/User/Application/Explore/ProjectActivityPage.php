<?php

declare(strict_types=1);

namespace App\User\Application\Explore;

use App\User\Domain\Enum\UserActivityType;

/** @psalm-suppress PossiblyUnusedProperty Consumed by Twig dashboard templates. */
final readonly class ProjectActivityPage
{
    public const int PAGE_SIZE = 10;

    /**
     * @return list<UserActivityType>
     */
    public static function feedTypes(): array
    {
        return [
            UserActivityType::JOINED,
            UserActivityType::FIRST_IMPORT,
            UserActivityType::IMPORT_MILESTONE,
            UserActivityType::POST_PUBLISHED,
            UserActivityType::COMMENT_CREATED,
            UserActivityType::HOSPITAL_ASSOCIATED,
            UserActivityType::HOSPITAL_OWNER_GRANTED,
        ];
    }

    /**
     * @param list<ProjectActivity> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public int $pageSize = self::PAGE_SIZE,
    ) {
    }

    /** @psalm-suppress PossiblyUnusedMethod Consumed by Twig dashboard templates. */
    public function hasMore(): bool
    {
        return null !== $this->nextCursor;
    }

    /** @psalm-suppress PossiblyUnusedMethod Consumed by Twig dashboard templates. */
    public function nextFrameId(): ?string
    {
        if (null === $this->nextCursor) {
            return null;
        }

        return ProjectActivityCursor::frameId($this->nextCursor);
    }
}
