<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Explore;

use App\User\Application\Contract\UserImportActivityProviderInterface;
use App\User\Application\Contract\UserPublishedCommentsProviderInterface;
use App\User\Application\Contract\UserPublishedPostsProviderInterface;
use App\User\Application\Explore\UserProfileView;
use App\User\Application\Explore\UserPublicRoleResolver;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Query\UserHospitalRelationsQuery;
use App\User\Infrastructure\Query\UserProfileActivityQuery;

final readonly class UserProfileFactory
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by Symfony DI. */
    public function __construct(
        private UserHospitalRelationsQuery $hospitalRelationsQuery,
        private UserImportActivityProviderInterface $importActivityProvider,
        private UserPublishedPostsProviderInterface $publishedPostsProvider,
        private UserPublishedCommentsProviderInterface $publishedCommentsProvider,
        private UserProfileActivityQuery $activityQuery,
    ) {
    }

    public function create(User $user, ?User $viewer): UserProfileView
    {
        $id = $user->getId();
        if (null === $id) {
            throw new \LogicException('User must be persisted.');
        }

        $username = $user->getUsername();
        if (null === $username || '' === $username) {
            throw new \LogicException('User has no username.');
        }

        $hospitalsByUser = $this->hospitalRelationsQuery->forUserIds([$id]);
        $importCounts = $this->importActivityProvider->countsByUserIds([$id]);
        $activityPage = $this->activityQuery->getPage($user, null);

        return new UserProfileView(
            publicId: $user->getPublicIdString(),
            username: $username,
            isAdmin: UserPublicRoleResolver::isAdmin($user),
            isParticipant: UserPublicRoleResolver::isParticipant($user),
            isBoardMember: UserPublicRoleResolver::isBoardMember($user),
            isSelf: $viewer instanceof User && $viewer->getId() === $id,
            createdAt: $user->getCreatedAt(),
            hospitals: $hospitalsByUser[$id] ?? [],
            successfulImportCount: $importCounts[$id] ?? 0,
            publishedPostCount: $this->publishedPostsProvider->countPublishedByUserId($id),
            commentCount: $this->publishedCommentsProvider->countOnPublishedPostsByUserId($id),
            activities: $activityPage->items,
            activityNextCursor: $activityPage->nextCursor,
        );
    }
}
