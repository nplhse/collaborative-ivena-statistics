<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Infrastructure\Security;

use App\User\Domain\Entity\User;
use App\User\Infrastructure\Security\UserAccountStatusChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Passing a check means not throwing, so the passing cases perform no assertion.
 */
#[CoversClass(UserAccountStatusChecker::class)]
final class UserAccountStatusCheckerTest extends TestCase
{
    /**
     * @return iterable<string, array{UserInterface}>
     */
    public static function accountsThatMaySignIn(): iterable
    {
        yield 'enabled and verified' => [self::user(true, true)];
        yield 'not an app user' => [new InMemoryUser('other', null)];
    }

    #[DataProvider('accountsThatMaySignIn')]
    public function testAccountPassesTheCheckBeforeAuthentication(UserInterface $user): void
    {
        $checker = new UserAccountStatusChecker();

        $this->expectNotToPerformAssertions();

        $checker->checkPreAuth($user);
    }

    /**
     * @return iterable<string, array{UserInterface}>
     */
    public static function accountsThatKeepTheirSession(): iterable
    {
        yield 'enabled and verified' => [self::user(true, true)];
        // An existing session of an unverified account stays valid; only a new sign-in needs the verified address.
        yield 'unverified' => [self::user(true, false)];
        yield 'not an app user' => [new InMemoryUser('other', null)];
    }

    #[DataProvider('accountsThatKeepTheirSession')]
    public function testAccountPassesTheCheckAfterAuthentication(UserInterface $user): void
    {
        $checker = new UserAccountStatusChecker();

        $this->expectNotToPerformAssertions();

        $checker->checkPostAuth($user);
    }

    /**
     * @return iterable<string, array{User}>
     */
    public static function accountsThatMayNotSignIn(): iterable
    {
        yield 'disabled' => [self::user(false, true)];
        yield 'unverified' => [self::user(true, false)];
    }

    #[DataProvider('accountsThatMayNotSignIn')]
    public function testAccountFailsTheCheckBeforeAuthenticationWithAGenericMessage(User $user): void
    {
        $checker = new UserAccountStatusChecker();

        $this->expectException(BadCredentialsException::class);
        $this->expectExceptionMessage('Invalid credentials.');

        $checker->checkPreAuth($user);
    }

    public function testDisabledAccountFailsTheCheckAfterAuthenticationWithAGenericMessage(): void
    {
        $checker = new UserAccountStatusChecker();

        $this->expectException(BadCredentialsException::class);
        $this->expectExceptionMessage('Invalid credentials.');

        $checker->checkPostAuth(self::user(false, true));
    }

    private static function user(bool $enabled, bool $verified): User
    {
        return new User()
            ->setUsername('status-user')
            ->setEmail('status-user@example.test')
            ->setPassword('hashed-password')
            ->setIsEnabled($enabled)
            ->setIsVerified($verified);
    }
}
