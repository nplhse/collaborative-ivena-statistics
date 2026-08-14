<?php

declare(strict_types=1);

namespace App\User\Application\Explore;

use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;

final class UserPublicRoleResolver
{
    public static function isAdmin(User $user): bool
    {
        return \in_array(UserRole::ADMIN, $user->getRoles(), true);
    }

    public static function isParticipant(User $user): bool
    {
        return \in_array(UserRole::PARTICIPANT, $user->getRoles(), true);
    }

    public static function isBoardMember(User $user): bool
    {
        return \in_array(UserRole::BOARD_MEMBER, $user->getRoles(), true);
    }

    public static function isUserOnly(User $user): bool
    {
        return !self::isAdmin($user) && !self::isParticipant($user) && !self::isBoardMember($user);
    }
}
