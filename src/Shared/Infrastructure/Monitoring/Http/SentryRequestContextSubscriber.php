<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Http;

use App\Shared\Infrastructure\Audit\AuditContext;
use App\Shared\Infrastructure\Consent\CookieConsentService;
use App\User\Domain\Entity\User;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\UserDataBag;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Request ids go onto the Sentry scope as early as possible, so even errors in the firewall carry them.
 * Route, bounded context and user are only known after routing and authentication, so they are set when the
 * controller is resolved. Only the numeric user id is attached, and only with analytics consent under the current
 * consent text (CookieConsentService::allowsUserIdInMonitoring()): without it, an event can still be matched to the
 * audit log through `request_id`, but not to a person in Sentry.
 *
 * @psalm-suppress UnusedClass
 */
final readonly class SentryRequestContextSubscriber
{
    public function __construct(
        private HubInterface $hub,
        private Security $security,
        private AuditContext $auditContext,
        private BoundedContextResolver $boundedContextResolver,
        private CookieConsentService $cookieConsentService,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 110)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!$this->hub->getClient() instanceof \Sentry\ClientInterface) {
            return;
        }

        $origin = $this->auditContext->getOrigin();
        if (\is_string($origin) && '' !== $origin) {
            $this->hub->configureScope(static function (Scope $scope) use ($origin): void {
                $scope->setTag('origin', $origin);
            });
        }

        $requestId = $this->auditContext->getRequestId();
        if (\is_string($requestId) && '' !== $requestId) {
            $this->hub->configureScope(static function (Scope $scope) use ($requestId): void {
                $scope->setTag('request_id', $requestId);
            });
        }

        $upstreamRequestId = $this->auditContext->getUpstreamRequestId();
        if (\is_string($upstreamRequestId) && '' !== $upstreamRequestId) {
            $this->hub->configureScope(static function (Scope $scope) use ($upstreamRequestId): void {
                $scope->setTag('upstream_request_id', $upstreamRequestId);
            });
        }
    }

    #[AsEventListener(event: KernelEvents::CONTROLLER, priority: 0)]
    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!$this->hub->getClient() instanceof \Sentry\ClientInterface) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        $controller = $request->attributes->get('_controller');
        $boundedContext = $this->boundedContextResolver->resolveFromController(\is_string($controller) ? $controller : null);
        $userData = $this->userData($request);

        $this->hub->configureScope(static function (Scope $scope) use ($route, $boundedContext, $userData): void {
            if (\is_string($route) && '' !== $route) {
                $scope->setTag('route', $route);
            }
            if (\is_string($boundedContext) && '' !== $boundedContext) {
                $scope->setTag('bounded_context', $boundedContext);
            }
            if ($userData instanceof UserDataBag) {
                $scope->setUser($userData);
            }
        });
    }

    private function userData(Request $request): ?UserDataBag
    {
        $user = $this->security->getUser();
        $actorUserId = $this->auditContext->getActorUserId();
        if (!$user instanceof User && null === $actorUserId) {
            return null;
        }

        $consent = $this->cookieConsentService->resolveForRequest($request, $user instanceof User ? $user : null);
        if (!$this->cookieConsentService->allowsUserIdInMonitoring($consent)) {
            return null;
        }

        $id = $user instanceof User ? $user->getId() : $actorUserId;

        return null === $id ? null : new UserDataBag(id: (string) $id);
    }
}
