<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Infrastructure\Security;

use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use App\User\Infrastructure\Security\Voter\UserProfileVoter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

final class UserProfileVoterTest extends TestCase
{
    private UserProfileVoter $voter;

    #[\Override]
    protected function setUp(): void
    {
        $this->voter = new UserProfileVoter(new RoleHierarchy([
            UserRole::ADMIN => [UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS],
        ]));
    }

    public function testParticipantMayViewAnyProfile(): void
    {
        $token = $this->createToken($this->createUser(1), [UserRole::USER, UserRole::PARTICIPANT]);

        self::assertSame(Voter::ACCESS_GRANTED, $this->voter->vote($token, $this->createUser(2), [UserProfileVoter::VIEW]));
    }

    public function testAdminMayViewAnyProfileViaRoleHierarchy(): void
    {
        $token = $this->createToken($this->createUser(1), [UserRole::USER, UserRole::ADMIN]);

        self::assertSame(Voter::ACCESS_GRANTED, $this->voter->vote($token, $this->createUser(2), [UserProfileVoter::VIEW]));
    }

    public function testUserWithoutParticipantRoleMayViewOwnProfile(): void
    {
        $token = $this->createToken($this->createUser(1), [UserRole::USER]);

        self::assertSame(Voter::ACCESS_GRANTED, $this->voter->vote($token, $this->createUser(1), [UserProfileVoter::VIEW]));
    }

    public function testUserWithoutParticipantRoleMayNotViewOtherProfiles(): void
    {
        $token = $this->createToken($this->createUser(1), [UserRole::USER]);

        self::assertSame(Voter::ACCESS_DENIED, $this->voter->vote($token, $this->createUser(2), [UserProfileVoter::VIEW]));
    }

    public function testUnsavedUsersAreNeverTheSameProfile(): void
    {
        $token = $this->createToken($this->createUser(null), [UserRole::USER]);

        self::assertSame(Voter::ACCESS_DENIED, $this->voter->vote($token, $this->createUser(null), [UserProfileVoter::VIEW]));
    }

    public function testAnonymousVisitorIsDenied(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);
        $token->method('getRoleNames')->willReturn([]);

        self::assertSame(Voter::ACCESS_DENIED, $this->voter->vote($token, $this->createUser(1), [UserProfileVoter::VIEW]));
    }

    public function testOtherAttributesAbstain(): void
    {
        $token = $this->createToken($this->createUser(1), [UserRole::USER]);

        self::assertSame(Voter::ACCESS_ABSTAIN, $this->voter->vote($token, $this->createUser(1), ['EDIT']));
    }

    private function createUser(?int $id): User&Stub
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }

    /**
     * @param list<string> $roleNames
     */
    private function createToken(User $user, array $roleNames): TokenInterface&Stub
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $token->method('getRoleNames')->willReturn($roleNames);

        return $token;
    }
}
