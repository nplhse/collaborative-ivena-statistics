<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Infrastructure\Security\TwoFactor;

use App\User\Domain\Entity\User;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorFailureThrottlingListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorToken;
use Scheb\TwoFactorBundle\Security\Http\Authenticator\Passport\Credentials\TwoFactorCodeCredentials;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * The per-account limit on two-factor codes (#1691), with the same limit as `two_factor_code` in rate_limiter.yaml
 * (5 per 15 minutes). That wrong codes at `/2fa/check` reach this listener and that a password login does not reset it
 * is checked once over HTTP in `TwoFactorLoginTest::testPasswordLoginDoesNotResetTheTwoFactorFailureCount`.
 */
#[CoversClass(TwoFactorFailureThrottlingListener::class)]
final class TwoFactorFailureThrottlingListenerTest extends TestCase
{
    private TwoFactorFailureThrottlingListener $listener;

    protected function setUp(): void
    {
        $this->listener = new TwoFactorFailureThrottlingListener(
            new RateLimiterFactory(['id' => 'two_factor_code', 'policy' => 'sliding_window', 'limit' => 5, 'interval' => '15 minutes'], new InMemoryStorage()),
            new MockClock('2026-11-28 10:00:00', 'UTC'),
        );
    }

    public function testTheSixthCodeOfAnAccountIsRefused(): void
    {
        $alice = $this->user('alice', 7);
        $this->checkCodes($alice, 5);

        $this->expectException(TooManyLoginAttemptsAuthenticationException::class);

        $this->checkCodes($alice, 1);
    }

    public function testTheLimitIsPerAccount(): void
    {
        $this->checkCodes($this->user('alice', 7), 5);

        // Bob's first code still gets through although Alice has used up her five.
        $this->checkCodes($this->user('bob', 8), 1);

        $this->addToAssertionCount(1);
    }

    public function testAnAcceptedCodeGivesTheFullAllowanceBack(): void
    {
        $alice = $this->user('alice', 7);
        $this->checkCodes($alice, 4);

        $this->listener->onLoginSuccess($this->successEvent($this->codePassport($alice)));

        $this->checkCodes($alice, 5);
        $this->addToAssertionCount(1);
    }

    public function testAPasswordCheckDoesNotCount(): void
    {
        $alice = $this->user('alice', 7);
        $this->checkCodes($alice, 4);

        $this->listener->onCheckPassport(new CheckPassportEvent($this->createStub(AuthenticatorInterface::class), $this->passwordPassport($alice)));

        // The fifth code is still allowed.
        $this->checkCodes($alice, 1);
        $this->addToAssertionCount(1);
    }

    public function testAPasswordLoginDoesNotReset(): void
    {
        $alice = $this->user('alice', 7);
        $this->checkCodes($alice, 5);

        $this->listener->onLoginSuccess($this->successEvent($this->passwordPassport($alice)));

        $this->expectException(TooManyLoginAttemptsAuthenticationException::class);
        $this->checkCodes($alice, 1);
    }

    /** @return iterable<string, array{int, int}> */
    public static function retryAfter(): iterable
    {
        // seconds until the next attempt, minutes in the message
        yield 'exactly one minute' => [60, 1];
        yield 'one second more' => [61, 2];
        yield 'a few seconds' => [5, 1];
        yield 'already over' => [0, 1];
        yield 'fifteen minutes' => [900, 15];
    }

    #[DataProvider('retryAfter')]
    public function testTheMinutesInTheMessageAreRoundedUp(int $seconds, int $minutes): void
    {
        $clock = new MockClock('2026-11-28 10:00:00', 'UTC');
        $limiter = $this->createStub(LimiterInterface::class);
        $limiter->method('consume')->willReturn(new RateLimit(0, $clock->now()->modify(\sprintf('+%d seconds', $seconds)), false, 5));
        $factory = $this->createStub(RateLimiterFactoryInterface::class);
        $factory->method('create')->willReturn($limiter);
        $listener = new TwoFactorFailureThrottlingListener($factory, $clock);

        try {
            $listener->onCheckPassport($this->checkEvent($this->user('alice', 7)));
            self::fail('The code should have been refused.');
        } catch (TooManyLoginAttemptsAuthenticationException $exception) {
            self::assertSame($minutes, $exception->getMessageData()['%minutes%']);
        }
    }

    private function checkCodes(User $user, int $times): void
    {
        for ($i = 0; $i < $times; ++$i) {
            $this->listener->onCheckPassport($this->checkEvent($user));
        }
    }

    private function checkEvent(User $user): CheckPassportEvent
    {
        return new CheckPassportEvent($this->createStub(AuthenticatorInterface::class), $this->codePassport($user));
    }

    private function successEvent(Passport $passport): LoginSuccessEvent
    {
        return new LoginSuccessEvent(
            $this->createStub(AuthenticatorInterface::class),
            $passport,
            new UsernamePasswordToken($passport->getUser(), 'main', $passport->getUser()->getRoles()),
            Request::create('/2fa/check'),
            null,
            'main',
        );
    }

    private function codePassport(User $user): Passport
    {
        $token = new TwoFactorToken(new UsernamePasswordToken($user, 'main', $user->getRoles()), null, 'main', ['totp']);

        return new Passport(new UserBadge($user->getUserIdentifier(), static fn (): User => $user), new TwoFactorCodeCredentials($token, '123456'));
    }

    private function passwordPassport(User $user): Passport
    {
        return new Passport(new UserBadge($user->getUserIdentifier(), static fn (): User => $user), new PasswordCredentials('password'));
    }

    private function user(string $username, int $id): User
    {
        $user = new User()->setUsername($username);
        new \ReflectionProperty(User::class, 'id')->setValue($user, $id);

        return $user;
    }
}
