<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Infrastructure\Security\AccountLock;

use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Tests\User\Doubles\InMemoryAccountLockWriter;
use App\Tests\User\Doubles\InMemoryLoginFailureRecorder;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Security\AccountLock\AccountLock;
use App\User\Infrastructure\Security\AccountLock\AccountLockedException;
use App\User\Infrastructure\Security\AccountLock\AccountLockListener;
use App\User\Infrastructure\Security\LoginFormAuthenticator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Which sign-ins the account lock refuses and which failures it counts (#1762). Counting is observed by arranging 19
 * wrong passwords: a counted 20th failure saves a lock.
 */
#[CoversClass(AccountLockListener::class)]
final class AccountLockListenerTest extends TestCase
{
    private InMemoryAccountLockWriter $writer;

    private AccountLock $accountLock;

    private AccountLockListener $listener;

    protected function setUp(): void
    {
        $this->writer = new InMemoryAccountLockWriter();
        $this->accountLock = new AccountLock(
            new RateLimiterFactory(['id' => 'login_account_failure', 'policy' => 'sliding_window', 'limit' => 20, 'interval' => '1 hour'], new InMemoryStorage()),
            $this->writer,
            $this->createStub(NotificationPublisherInterface::class),
            new MockClock('2026-11-27 10:00:00', 'UTC'),
            new NullLogger(),
            new InMemoryLoginFailureRecorder(),
            true,
        );
        $this->listener = new AccountLockListener($this->accountLock);
    }

    /** @return iterable<string, array{bool, bool, bool, bool, AuthenticationException, int}> */
    public static function failures(): iterable
    {
        // form authenticator, password resolved, enabled, verified, exception, expected locks
        yield 'wrong password' => [true, false, true, true, new BadCredentialsException(), 1];
        yield 'another authenticator' => [false, false, true, true, new BadCredentialsException(), 0];
        yield 'password already checked' => [true, true, true, true, new BadCredentialsException(), 0];
        yield 'login throttling' => [true, false, true, true, new TooManyLoginAttemptsAuthenticationException(), 0];
        yield 'disabled account' => [true, false, false, true, new BadCredentialsException(), 0];
        yield 'unverified account' => [true, false, true, false, new BadCredentialsException(), 0];
    }

    #[DataProvider('failures')]
    public function testOnlyAWrongPasswordForAnActiveAccountCounts(bool $form, bool $resolved, bool $enabled, bool $verified, AuthenticationException $exception, int $locks): void
    {
        $user = $this->user()->setIsEnabled($enabled)->setIsVerified($verified);
        $this->failPasswords($user, 19);
        $passport = $this->passport(static fn (): User => $user, $resolved);

        $this->listener->onLoginFailure($this->failureEvent($passport, $exception, $form));

        self::assertSame($locks, $this->writer->saves);
    }

    public function testAFailureForAnUnknownUsernameCountsForNobody(): void
    {
        $passport = $this->passport(static fn (): never => throw new UserNotFoundException());

        $this->listener->onLoginFailure($this->failureEvent($passport, new BadCredentialsException(), true));

        self::assertSame(0, $this->writer->saves);
    }

    public function testALockedAccountIsRefusedBeforeThePasswordCheck(): void
    {
        $user = $this->user();
        $this->failPasswords($user, 20);

        $this->expectException(AccountLockedException::class);

        $this->listener->onCheckPassport(new CheckPassportEvent($this->formAuthenticator(), $this->passport(static fn (): User => $user)));
    }

    public function testALockedAccountMayStillUseOtherAuthenticators(): void
    {
        $user = $this->user();
        $this->failPasswords($user, 20);
        $event = new CheckPassportEvent($this->createStub(AuthenticatorInterface::class), $this->passport(static fn (): User => $user));

        $this->listener->onCheckPassport($event);

        $this->addToAssertionCount(1);
    }

    private function failPasswords(User $user, int $times): void
    {
        for ($i = 0; $i < $times; ++$i) {
            $this->accountLock->recordFailedPassword($user);
        }
    }

    /** @param \Closure(string): User $loader */
    private function passport(\Closure $loader, bool $passwordChecked = false): Passport
    {
        $credentials = new PasswordCredentials('wrong-password');
        if ($passwordChecked) {
            $credentials->markResolved();
        }

        return new Passport(new UserBadge('target', $loader), $credentials);
    }

    private function failureEvent(Passport $passport, AuthenticationException $exception, bool $form): LoginFailureEvent
    {
        $authenticator = $form ? $this->formAuthenticator() : $this->createStub(AuthenticatorInterface::class);

        return new LoginFailureEvent($exception, $authenticator, Request::create('/login'), null, 'main', $passport);
    }

    /** Only its type matters to the listener. */
    private function formAuthenticator(): LoginFormAuthenticator
    {
        return new \ReflectionClass(LoginFormAuthenticator::class)->newInstanceWithoutConstructor();
    }

    private function user(): User
    {
        $user = new User()->setUsername('target')->setIsEnabled(true)->setIsVerified(true);
        new \ReflectionProperty(User::class, 'id')->setValue($user, 7);

        return $user;
    }
}
