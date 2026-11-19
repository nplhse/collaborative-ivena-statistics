<?php

declare(strict_types=1);

namespace App\User\Application\Explore;

use App\User\Domain\Enum\UserActivityType;

/** @psalm-suppress PossiblyUnusedProperty Consumed by Twig dashboard templates. */
final readonly class ProjectActivityPage
{
    public const int PAGE_SIZE = 10;

    public const int PREVIEW_SIZE = 5;

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
     * Types that carry a hospital in their metadata (`hospitalPublicId`), shown on the hospital
     * page (#488). Unlike the project feed this includes removed access and ownership.
     *
     * @return list<UserActivityType>
     */
    public static function hospitalFeedTypes(): array
    {
        return [
            UserActivityType::FIRST_IMPORT,
            UserActivityType::IMPORT_MILESTONE,
            UserActivityType::HOSPITAL_ASSOCIATED,
            UserActivityType::HOSPITAL_DISASSOCIATED,
            UserActivityType::HOSPITAL_OWNER_GRANTED,
            UserActivityType::HOSPITAL_OWNER_REVOKED,
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
    public function nextFrameId(string $prefix = ProjectActivityCursor::FRAME_PREFIX_DASHBOARD): ?string
    {
        if (null === $this->nextCursor) {
            return null;
        }

        return ProjectActivityCursor::frameId($this->nextCursor, $prefix);
    }
}
