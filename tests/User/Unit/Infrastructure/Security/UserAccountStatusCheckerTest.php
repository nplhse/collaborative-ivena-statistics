<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Infrastructure\Security;

use App\User\Domain\Entity\User;
use App\User\Infrastructure\Security\UserAccountStatusChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class UserAccountStatusCheckerTest extends TestCase
{
    private UserAccountStatusChecker $checker;

    #[\Override]
    protected function setUp(): void
    {
        $this->checker = new UserAccountStatusChecker();
    }

    public function testEnabledVerifiedUserPassesPreAndPostAuthChecks(): void
    {
        $user = $this->createUser(true, true);

        $this->checker->checkPreAuth($user);
        $this->checker->checkPostAuth($user);

        self::assertTrue($user->isEnabled());
        self::assertTrue($user->isVerified());
    }

    public function testDisabledUserFailsPreAuthCheck(): void
    {
        $user = $this->createUser(false, true);

        $this->expectException(BadCredentialsException::class);
        $this->expectExceptionMessage('Invalid credentials.');

        $this->checker->checkPreAuth($user);
    }

    public function testDisabledUserFailsPostAuthCheck(): void
    {
        $user = $this->createUser(false, true);

        $this->expectException(BadCredentialsException::class);
        $this->expectExceptionMessage('Invalid credentials.');

        $this->checker->checkPostAuth($user);
    }

    public function testUnverifiedUserFailsPreAuthCheck(): void
    {
        $user = $this->createUser(true, false);

        $this->expectException(BadCredentialsException::class);
        $this->expectExceptionMessage('Invalid credentials.');

        $this->checker->checkPreAuth($user);
    }

    public function testUnverifiedUserPassesPostAuthCheckForExistingSession(): void
    {
        $user = $this->createUser(true, false);

        $this->checker->checkPostAuth($user);

        self::assertFalse($user->isVerified());
    }

    public function testNonAppUserIsIgnored(): void
    {
        $user = new InMemoryUser('other', null);

        $this->checker->checkPreAuth($user);
        $this->checker->checkPostAuth($user);

        self::assertSame('other', $user->getUserIdentifier());
    }

    private function createUser(bool $enabled, bool $verified): User
    {
        $user = new User();
        $user->setUsername('status-user');
        $user->setEmail('status-user@example.test');
        $user->setPassword('hashed-password');
        $user->setIsEnabled($enabled);
        $user->setIsVerified($verified);

        return $user;
    }
}
