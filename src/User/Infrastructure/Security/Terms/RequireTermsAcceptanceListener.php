<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security\Terms;

use App\Shared\Application\Navigation\Contract\NavigationPageLinksProviderInterface;
use App\User\Domain\Entity\User;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Sends signed-in users who have not accepted the configured version of the terms of use to the acceptance page
 * (#1564, ADR 043). Until they accept they can only reach that page, logout, the cookie and language choice and the
 * legal pages (terms, imprint, privacy). Page loads are redirected; other requests get a 403. Runs after the firewall
 * (priority 8) and before the two-factor obligation (priority 0), so the terms come first. Administrators who
 * impersonate a user do not accept for them.
 *
 * @psalm-suppress UnusedClass
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 1)]
final readonly class RequireTermsAcceptanceListener
{
    public const string ACCEPT_ROUTE = 'app_terms_accept';

    /** Session key of the page to return to after accepting. */
    public const string TARGET_PATH_KEY = '_app_terms_target_path';

    /** Routes a user without an acceptance may still use. */
    private const array ALLOWED_ROUTES = [
        self::ACCEPT_ROUTE,
        'app_logout',
        'app_locale_switch',
        'app_cookie_banner',
        'app_cookie_preferences',
        'app_cookie_preferences_save',
    ];

    /** CMS pages that stay readable: the terms themselves and the legal notice. */
    private const array LEGAL_PAGE_KEYS = ['terms', 'imprint', 'privacy'];

    /** @psalm-suppress PossiblyUnusedMethod Built by the container. */
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private TermsAcceptanceStatus $status,
        private UrlGeneratorInterface $urlGenerator,
        private NavigationPageLinksProviderInterface $pageLinks,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        // Reading the token starts the session, so it is read only when a version is configured, the visitor already
        // has a session and the route is not stateless (public media files, #1570).
        if (!$event->isMainRequest() || !$this->status->isRequired() || !$request->hasPreviousSession()
            || true === $request->attributes->get('_stateless')) {
            return;
        }

        $route = $request->attributes->get('_route');
        // Profiler, error pages and other internal routes start with an underscore.
        if (!\is_string($route) || \in_array($route, self::ALLOWED_ROUTES, true) || str_starts_with($route, '_')) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if (!$token instanceof TokenInterface || $token instanceof TwoFactorTokenInterface || $token instanceof SwitchUserToken) {
            return;
        }

        $user = $token->getUser();
        $session = $request->hasSession() ? $request->getSession() : null;
        if (!$user instanceof User || !$this->status->isOutstanding($user, $session) || $this->isLegalPage($request)) {
            return;
        }

        if (!$request->isMethod(Request::METHOD_GET) || $request->isXmlHttpRequest()) {
            $event->setResponse(new Response('', Response::HTTP_FORBIDDEN));

            return;
        }

        $session?->set(self::TARGET_PATH_KEY, $request->getRequestUri());
        $event->setResponse(new RedirectResponse($this->urlGenerator->generate(self::ACCEPT_ROUTE)));
    }

    private function isLegalPage(Request $request): bool
    {
        if ('app_page_show' !== $request->attributes->get('_route')) {
            return false;
        }

        $path = $request->getBaseUrl().$request->getPathInfo();

        return array_any($this->pageLinks->linksForKeys(...self::LEGAL_PAGE_KEYS), fn (array $link): bool => $link['url'] === $path);
    }
}
