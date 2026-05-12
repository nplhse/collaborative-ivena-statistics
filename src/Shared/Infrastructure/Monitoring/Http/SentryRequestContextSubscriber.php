<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Http;

use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\UserDataBag;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @psalm-suppress UnusedClass
 */
final readonly class SentryRequestContextSubscriber
{
    public function __construct(
        private HubInterface $hub,
        private Security $security,
        private AuditContext $auditContext,
        private BoundedContextResolver $boundedContextResolver,
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

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        if (\is_string($route) && '' !== $route) {
            $this->hub->configureScope(static function (Scope $scope) use ($route): void {
                $scope->setTag('route', $route);
            });
        }

        $boundedContext = $this->boundedContextResolver->resolveFromController(
            $request->attributes->get('_controller')
        );
        if (\is_string($boundedContext) && '' !== $boundedContext) {
            $this->hub->configureScope(static function (Scope $scope) use ($boundedContext): void {
                $scope->setTag('bounded_context', $boundedContext);
            });
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

        $user = $this->security->getUser();
        if ($user instanceof User) {
            $this->hub->configureScope(static function (Scope $scope) use ($user): void {
                $scope->setUser(new UserDataBag(
                    id: null === $user->getId() ? null : (string) $user->getId(),
                    username: $user->getUserIdentifier(),
                ));
            });

            return;
        }

        $actorUserId = $this->auditContext->getActorUserId();
        if (null !== $actorUserId) {
            $this->hub->configureScope(static function (Scope $scope) use ($actorUserId): void {
                $scope->setUser(new UserDataBag(id: (string) $actorUserId));
            });
        }
    }
}
