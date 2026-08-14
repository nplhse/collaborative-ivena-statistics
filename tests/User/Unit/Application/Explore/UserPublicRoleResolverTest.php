<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Application\Explore;

use App\User\Application\Explore\UserPublicRoleResolver;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use PHPUnit\Framework\TestCase;

final class UserPublicRoleResolverTest extends TestCase
{
    public function testUserOnlyWhenNoPublicSpecialtyRoleIsPresent(): void
    {
        $user = new User()->setRoles([UserRole::USER]);

        self::assertTrue(UserPublicRoleResolver::isUserOnly($user));
        self::assertFalse(UserPublicRoleResolver::isAdmin($user));
        self::assertFalse(UserPublicRoleResolver::isParticipant($user));
        self::assertFalse(UserPublicRoleResolver::isBoardMember($user));
    }

    public function testSpecialtyRolesAreNotUserOnly(): void
    {
        self::assertFalse(UserPublicRoleResolver::isUserOnly(
            new User()->setRoles([UserRole::USER, UserRole::PARTICIPANT]),
        ));
        self::assertFalse(UserPublicRoleResolver::isUserOnly(
            new User()->setRoles([UserRole::USER, UserRole::ADMIN]),
        ));
        self::assertFalse(UserPublicRoleResolver::isUserOnly(
            new User()->setRoles([UserRole::USER, UserRole::PARTICIPANT, UserRole::BOARD_MEMBER]),
        ));
    }
}
