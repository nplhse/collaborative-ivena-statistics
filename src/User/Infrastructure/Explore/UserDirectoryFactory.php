<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Explore;

use App\User\Application\Explore\UserDirectoryPage;
use App\User\Application\Explore\UserListItem;
use App\User\Application\Explore\UserPublicRoleResolver;
use App\User\Application\Explore\UserQueryParameters;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Query\ListExploreUsersQuery;
use App\User\Infrastructure\Query\UserHospitalRelationsQuery;

final readonly class UserDirectoryFactory
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by Symfony DI. */
    public function __construct(
        private ListExploreUsersQuery $listExploreUsersQuery,
        private UserHospitalRelationsQuery $hospitalRelationsQuery,
    ) {
    }

    public function create(UserQueryParameters $query): UserDirectoryPage
    {
        $paginator = ($this->listExploreUsersQuery)($query);

        /** @var list<User> $users */
        $users = [];
        foreach ($paginator->getResults() as $user) {
            if ($user instanceof User) {
                $users[] = $user;
            }
        }

        $userIds = [];
        foreach ($users as $user) {
            $id = $user->getId();
            if (null !== $id) {
                $userIds[] = $id;
            }
        }

        $hospitalsByUser = $this->hospitalRelationsQuery->forUserIds($userIds);

        $items = [];
        foreach ($users as $user) {
            $id = $user->getId();
            if (null === $id) {
                continue;
            }

            $username = $user->getUsername();
            if (null === $username || '' === $username) {
                continue;
            }

            try {
                $publicId = $user->getPublicIdString();
            } catch (\LogicException) {
                continue;
            }

            $items[] = new UserListItem(
                publicId: $publicId,
                username: $username,
                isAdmin: UserPublicRoleResolver::isAdmin($user),
                isParticipant: UserPublicRoleResolver::isParticipant($user),
                isBoardMember: UserPublicRoleResolver::isBoardMember($user),
                hospitals: $hospitalsByUser[$id] ?? [],
                createdAt: $user->getCreatedAt(),
            );
        }

        return new UserDirectoryPage(
            paginator: $paginator,
            items: $items,
            hospitalChoices: $this->hospitalRelationsQuery->hospitalFilterChoices(),
        );
    }
}
