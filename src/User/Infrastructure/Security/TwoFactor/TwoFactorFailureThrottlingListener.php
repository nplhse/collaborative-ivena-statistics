<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security\TwoFactor;

use App\User\Domain\Entity\User;
use Psr\Clock\ClockInterface;
use Scheb\TwoFactorBundle\Security\Http\Authenticator\Passport\Credentials\TwoFactorCodeCredentials;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Limits two-factor codes per account (#1691), independently of `login_throttling`.
 *
 * `login_throttling` keys on IP and username and is reset by every successful password login, so it alone would allow
 * more code guesses than its limit. This limiter keys on the account, takes a token for every code before it is checked
 * (so concurrent attempts count too) and is only reset when a code was accepted, never by the password or the login
 * link.
 *
 * @psalm-suppress UnusedClass
 */
final readonly class TwoFactorFailureThrottlingListener
{
    public function __construct(
        #[Autowire(service: 'limiter.two_factor_code')]
        private RateLimiterFactoryInterface $limiter,
        private ClockInterface $clock,
    ) {
    }

    /** Runs right after Symfony's login throttling (2080), before the code is checked. */
    #[AsEventListener(priority: 2070)]
    public function onCheckPassport(CheckPassportEvent $event): void
    {
        $key = $this->limiterKey($event->getPassport());
        if (null === $key) {
            return;
        }

        $limit = $this->limiter->create($key)->consume();
        if ($limit->isAccepted()) {
            return;
        }

        $seconds = $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp();

        throw new TooManyLoginAttemptsAuthenticationException(max(1, (int) ceil($seconds / 60)));
    }

    #[AsEventListener]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $key = $this->limiterKey($event->getPassport());
        if (null !== $key) {
            $this->limiter->create($key)->reset();
        }
    }

    /** Null for every passport that does not carry a two-factor code (password, login link, confirmation). */
    private function limiterKey(Passport $passport): ?string
    {
        $credentials = $passport->getBadge(TwoFactorCodeCredentials::class);
        if (!$credentials instanceof TwoFactorCodeCredentials) {
            return null;
        }

        $user = $credentials->getTwoFactorToken()->getUser();
        $id = $user instanceof User ? $user->getId() : null;

        return null !== $id
            ? 'user-'.$id
            : 'identifier-'.hash('sha256', mb_strtolower($user->getUserIdentifier()));
    }
}
