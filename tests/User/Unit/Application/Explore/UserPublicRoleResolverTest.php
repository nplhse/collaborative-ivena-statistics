<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Application\Explore;

use App\User\Application\Explore\UserPublicRoleResolver;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserPublicRoleResolver::class)]
final class UserPublicRoleResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, array{admin: bool, participant: bool, boardMember: bool, userOnly: bool}}>
     */
    public static function roles(): iterable
    {
        yield 'user only' => [[UserRole::USER], ['admin' => false, 'participant' => false, 'boardMember' => false, 'userOnly' => true]];
        yield 'participant' => [[UserRole::USER, UserRole::PARTICIPANT], ['admin' => false, 'participant' => true, 'boardMember' => false, 'userOnly' => false]];
        // The flags read the stored roles, not the role hierarchy: an admin is no participant here.
        yield 'admin' => [[UserRole::USER, UserRole::ADMIN], ['admin' => true, 'participant' => false, 'boardMember' => false, 'userOnly' => false]];
        yield 'board member and participant' => [[UserRole::USER, UserRole::PARTICIPANT, UserRole::BOARD_MEMBER], ['admin' => false, 'participant' => true, 'boardMember' => true, 'userOnly' => false]];
    }

    /**
     * @param list<string>                                                             $roles
     * @param array{admin: bool, participant: bool, boardMember: bool, userOnly: bool} $expected
     */
    #[DataProvider('roles')]
    public function testFlagsFollowTheStoredRoles(array $roles, array $expected): void
    {
        $user = new User()->setRoles($roles);

        $flags = [
            'admin' => UserPublicRoleResolver::isAdmin($user),
            'participant' => UserPublicRoleResolver::isParticipant($user),
            'boardMember' => UserPublicRoleResolver::isBoardMember($user),
            'userOnly' => UserPublicRoleResolver::isUserOnly($user),
        ];

        self::assertSame($expected, $flags);
    }
}
