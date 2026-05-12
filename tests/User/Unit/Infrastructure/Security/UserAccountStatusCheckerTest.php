<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Infrastructure\Security;

use App\User\Domain\Entity\User;
use App\User\Infrastructure\Security\UserAccountStatusChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class UserAccountStatusCheckerTest extends TestCase
{
    private UserAccountStatusChecker $checker;

    #[\Override]
    protected function setUp(): void
    {
        $this->checker = new UserAccountStatusChecker();
    }

    public function testEnabledUserPassesPreAndPostAuthChecks(): void
    {
        $user = $this->createUser(true);

        $this->checker->checkPreAuth($user);
        $this->checker->checkPostAuth($user);

        self::assertTrue($user->isEnabled());
    }

    public function testDisabledUserFailsPreAuthCheck(): void
    {
        $user = $this->createUser(false);

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('Account is disabled.');

        $this->checker->checkPreAuth($user);
    }

    public function testDisabledUserFailsPostAuthCheck(): void
    {
        $user = $this->createUser(false);

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('Account is disabled.');

        $this->checker->checkPostAuth($user);
    }

    public function testNonAppUserIsIgnored(): void
    {
        $user = new InMemoryUser('other', null);

        $this->checker->checkPreAuth($user);
        $this->checker->checkPostAuth($user);

        self::assertSame('other', $user->getUserIdentifier());
    }

    private function createUser(bool $enabled): User
    {
        $user = new User();
        $user->setUsername('status-user');
        $user->setEmail('status-user@example.test');
        $user->setPassword('hashed-password');
        $user->setIsEnabled($enabled);

        return $user;
    }
}
