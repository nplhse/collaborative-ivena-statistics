<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use App\Shared\Infrastructure\Consent\CookieConsentService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Signing in with a login link needs a cookie-consent decision, like the password login (`LoginFormAuthenticator`).
 * Runs before the link is consumed (the user checker loads the user at priority 256), so the link stays usable once the
 * visitor has decided (#1355).
 *
 * @psalm-suppress UnusedClass
 */
final readonly class LoginLinkConsentListener
{
    private const string CHECK_ROUTE = 'app_login_link_check';

    public function __construct(
        private RequestStack $requestStack,
        private CookieConsentService $cookieConsentService,
    ) {
    }

    #[AsEventListener(priority: 512)]
    public function __invoke(CheckPassportEvent $event): void
    {
        // Password logins check the consent in LoginFormAuthenticator.
        if ($event->getPassport()->hasBadge(PasswordCredentials::class)) {
            return;
        }

        $request = $this->requestStack->getMainRequest();
        if (!$request instanceof Request || self::CHECK_ROUTE !== $request->attributes->get('_route')) {
            return;
        }

        $consent = $this->cookieConsentService->resolveForRequest($request, null);
        if (!$consent->getDecidedAt() instanceof \DateTimeImmutable) {
            throw new CustomUserMessageAuthenticationException('flash.security.cookie_consent_required');
        }
    }
}
