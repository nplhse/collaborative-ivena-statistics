<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security\AccountLock;

use App\User\Domain\Entity\User;
use App\User\Infrastructure\Security\LoginFormAuthenticator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\LoginLinkAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Applies the account lock (#1762) to the sign-in form and the login link.
 *
 * Password confirmation of a signed-in user (`ConfirmPasswordAuthenticator`) and two-factor codes (own limit, #1691)
 * are neither refused nor counted. Only a wrong password for an enabled, verified account counts; unknown usernames,
 * CSRF errors, throttling and refusals for disabled or unverified accounts do not.
 *
 * @psalm-suppress UnusedClass
 */
final readonly class AccountLockListener
{
    public function __construct(
        private AccountLock $accountLock,
    ) {
    }

    /**
     * Runs right after the user checker (256), which loads the user, and before the password check (0). Loading the user
     * of a login link consumes the link, so this must not run before the cookie-consent check (512,
     * LoginLinkConsentListener).
     */
    #[AsEventListener(priority: 255)]
    public function onCheckPassport(CheckPassportEvent $event): void
    {
        if (!$this->appliesTo($event->getAuthenticator())) {
            return;
        }

        $user = $this->user($event->getPassport());
        if ($user instanceof User && $this->accountLock->isLocked($user)) {
            throw new AccountLockedException('Invalid credentials.');
        }
    }

    #[AsEventListener]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $passport = $event->getPassport();
        if (!$event->getAuthenticator() instanceof LoginFormAuthenticator
            || BadCredentialsException::class !== $event->getException()::class
            || !$passport instanceof Passport) {
            return;
        }

        $credentials = $passport->getBadge(PasswordCredentials::class);
        if (!$credentials instanceof PasswordCredentials || $credentials->isResolved()) {
            return;
        }

        $user = $this->user($passport);
        if ($user instanceof User && $user->isEnabled() && $user->isVerified()) {
            $this->accountLock->recordFailedPassword($user);
        }
    }

    private function appliesTo(AuthenticatorInterface $authenticator): bool
    {
        return $authenticator instanceof LoginFormAuthenticator || $authenticator instanceof LoginLinkAuthenticator;
    }

    private function user(Passport $passport): ?User
    {
        try {
            $user = $passport->getUser();
        } catch (AuthenticationException) {
            return null;
        }

        return $user instanceof User ? $user : null;
    }
}
