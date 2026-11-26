<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Maintenance;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Twig\Environment;

/**
 * While the maintenance mode is on (#1680, ADR 046), only signed-in administrators use the application, also while
 * they impersonate another user. Everyone else gets a 503 with the maintenance page; background requests (forms in
 * frames, Live components, polling) get an empty 503. The health check, the sign-in with its second factor, logout,
 * the cookie and language choice and media stay reachable. Runs after the firewall (priority 8) and before the
 * terms and two-factor listeners (priorities 1 and 0), which would otherwise redirect blocked users first.
 *
 * @psalm-suppress UnusedClass
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final readonly class MaintenanceModeListener
{
    private const int RETRY_AFTER_SECONDS = 300;

    /** Routes everyone may use during the maintenance. */
    private const array ALLOWED_ROUTES = [
        'app_health',
        'app_login',
        'app_two_factor_login',
        'app_two_factor_check',
        'app_logout',
        'app_locale_switch',
        'app_cookie_banner',
        'app_cookie_preferences',
        'app_cookie_preferences_save',
        'app_media_file',
        'app_media_legacy_file',
    ];

    public function __construct(
        private MaintenanceMode $maintenanceMode,
        private TokenStorageInterface $tokenStorage,
        private Environment $twig,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->maintenanceMode->isEnabled()) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        // Profiler, error pages and other internal routes start with an underscore.
        if (\is_string($route) && (\in_array($route, self::ALLOWED_ROUTES, true) || str_starts_with($route, '_'))) {
            return;
        }

        // Reading the token starts the session, so it is read only when the visitor already has one (#1570).
        if ($request->hasPreviousSession() && $this->isAdministrator($this->tokenStorage->getToken())) {
            return;
        }

        $headers = ['Retry-After' => (string) self::RETRY_AFTER_SECONDS, 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex'];
        if (!$request->isMethod(Request::METHOD_GET) || $request->isXmlHttpRequest() || $request->headers->has('Turbo-Frame')) {
            $event->setResponse(new Response('', Response::HTTP_SERVICE_UNAVAILABLE, $headers));

            return;
        }

        $event->setResponse(new Response(
            $this->twig->render('@Shared/maintenance/maintenance.html.twig'),
            Response::HTTP_SERVICE_UNAVAILABLE,
            $headers,
        ));
    }

    private function isAdministrator(?TokenInterface $token): bool
    {
        if ($token instanceof SwitchUserToken) {
            $token = $token->getOriginalToken();
        }

        // A token waiting for the second factor has no roles yet, so it does not count.
        return $token instanceof TokenInterface && \in_array('ROLE_ADMIN', $token->getRoleNames(), true);
    }
}
