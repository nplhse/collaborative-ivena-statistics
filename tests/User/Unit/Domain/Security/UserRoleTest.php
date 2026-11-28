<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Domain\Security;

use App\User\Domain\Security\UserRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserRole::class)]
final class UserRoleTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function roles(): iterable
    {
        yield 'user only' => [[UserRole::USER], false];
        // Stored roles only: the role hierarchy, in which admins are participants, is not applied.
        yield 'admin' => [[UserRole::ADMIN], false];
        yield 'no roles value' => [null, false];
        yield 'a string instead of a list' => ['ROLE_PARTICIPANT', false];
        yield 'user and participant' => [[UserRole::USER, UserRole::PARTICIPANT], true];
        yield 'participant only' => [[UserRole::PARTICIPANT], true];
    }

    #[DataProvider('roles')]
    public function testContainsParticipantIgnoresRoleHierarchyAndUserRole(mixed $roles, bool $expected): void
    {
        $contains = UserRole::containsParticipant($roles);

        self::assertSame($expected, $contains);
    }
}
