<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Infrastructure\Security;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Infrastructure\Security\Voter\AllocationVoter;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class AllocationVoterTest extends TestCase
{
    private AllocationVoter $voter;

    #[\Override]
    protected function setUp(): void
    {
        $this->voter = new AllocationVoter();
    }

    public function testViewGrantedForAuthenticatedUser(): void
    {
        $user = $this->createUser([UserRole::USER]);
        $allocation = $this->createMock(Allocation::class);
        $token = $this->createToken($user);

        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter->vote($token, $allocation, [AllocationVoter::VIEW]),
        );
    }

    public function testViewDeniedForAnonymousUser(): void
    {
        $allocation = $this->createMock(Allocation::class);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter->vote($token, $allocation, [AllocationVoter::VIEW]),
        );
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(array $roles): User&MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn($roles);

        return $user;
    }

    private function createToken(User $user): TokenInterface&MockObject
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}
