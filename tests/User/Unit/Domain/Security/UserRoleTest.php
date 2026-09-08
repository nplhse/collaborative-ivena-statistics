<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Domain\Security;

use App\User\Domain\Security\UserRole;
use PHPUnit\Framework\TestCase;

final class UserRoleTest extends TestCase
{
    public function testContainsParticipantIgnoresRoleHierarchyAndUserRole(): void
    {
        self::assertFalse(UserRole::containsParticipant([UserRole::USER]));
        self::assertFalse(UserRole::containsParticipant([UserRole::ADMIN]));
        self::assertFalse(UserRole::containsParticipant(null));
        self::assertFalse(UserRole::containsParticipant('ROLE_PARTICIPANT'));
        self::assertTrue(UserRole::containsParticipant([UserRole::USER, UserRole::PARTICIPANT]));
        self::assertTrue(UserRole::containsParticipant([UserRole::PARTICIPANT]));
    }
}
