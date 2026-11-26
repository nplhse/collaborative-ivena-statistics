<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security\TwoFactor;

use App\User\Domain\Entity\User;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Sends administrators without two-factor authentication to its setup while the obligation is on (#1357, ADR 040,
 * `app.security.two_factor.required_for_admins`). Until it is set up they can only reach the setup, the password
 * confirmation, logout and the cookie and language choice. Page loads are redirected; other requests (forms, polling
 * in the background) get a 403. Runs after the firewall (priority 8).
 *
 * @psalm-suppress UnusedClass
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
final readonly class RequireTwoFactorListener
{
    private const string SETUP_ROUTE = 'app_settings_two_factor';

    /** Routes an administrator without two-factor authentication may still use. */
    private const array ALLOWED_ROUTES = [
        'app_settings_two_factor',
        'app_settings_two_factor_enable',
        'app_settings_two_factor_backup_codes',
        'app_confirm_password',
        // The terms of use come first (#1564); otherwise the two pages would send each other back and forth.
        'app_terms_accept',
        'app_logout',
        'app_locale_switch',
        'app_cookie_banner',
        'app_cookie_preferences',
        'app_cookie_preferences_save',
    ];

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private TwoFactorAvailability $availability,
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire('%app.cookie_prefix%REMEMBERME')]
        private string $rememberMeCookie,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        // Reading the token starts the session and makes the response private, so it is read only when the obligation
        // is on, the visitor is signed in (a session or a remember-me cookie, which signs in within this request, #1689)
        // and the route is not stateless (public media files, #1570).
        if (!$event->isMainRequest() || !$this->availability->isRequiredForAdmins()
            || (!$request->hasPreviousSession() && !$request->cookies->has($this->rememberMeCookie))
            || true === $request->attributes->get('_stateless')) {
            return;
        }

        $route = $request->attributes->get('_route');
        // Profiler, error pages and other internal routes start with an underscore.
        if (!\is_string($route) || \in_array($route, self::ALLOWED_ROUTES, true) || str_starts_with($route, '_')) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if (!$token instanceof TokenInterface || $token instanceof TwoFactorTokenInterface) {
            return;
        }

        // An administrator who impersonates someone is checked as themselves (#1689): without two-factor authentication
        // they leave the impersonation first and are then sent to the setup.
        $impersonating = $token instanceof SwitchUserToken;
        $user = $token instanceof SwitchUserToken ? $token->getOriginalToken()->getUser() : $token->getUser();
        if (!$user instanceof User || $user->isTotpAuthenticationEnabled() || !$this->availability->isRequiredFor($user)) {
            return;
        }

        if (!$request->isMethod(Request::METHOD_GET) || $request->isXmlHttpRequest()) {
            $event->setResponse(new Response('', Response::HTTP_FORBIDDEN));

            return;
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('warning', new TranslatableMessage('flash.settings.two_factor.required', domain: 'user'));
        }

        $event->setResponse(new RedirectResponse($impersonating
            ? $this->urlGenerator->generate('app_default', ['_switch_user' => '_exit'])
            : $this->urlGenerator->generate(self::SETUP_ROUTE)));
    }
}
