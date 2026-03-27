<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit\Http;

use App\Shared\Infrastructure\Audit\AuditContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * @psalm-suppress UnusedClass
 */
final readonly class AuditRequestSubscriber
{
    private const string HEADER = 'X-Request-Id';

    public function __construct(
        private AuditContext $auditContext,
        private Security $security,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 120)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $headerId = $request->headers->get(self::HEADER);
        $id = \is_string($headerId) && $this->isValidRequestId($headerId)
            ? $headerId
            : Uuid::v7()->toRfc4122();

        $this->auditContext->setRequestId($id);
        $this->auditContext->setOrigin(AuditContext::ORIGIN_HTTP);
        $this->auditContext->applySecurityUser($this->security);

        $clientIp = $request->getClientIp();
        $this->auditContext->setClientIp(\is_string($clientIp) && '' !== $clientIp ? $clientIp : null);

        $userAgent = $request->headers->get('User-Agent');
        $this->auditContext->setUserAgent(\is_string($userAgent) && '' !== $userAgent ? $userAgent : null);
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $id = $this->auditContext->getRequestId();
        if (\is_string($id) && '' !== $id) {
            $event->getResponse()->headers->set(self::HEADER, $id);
        }
    }

    private function isValidRequestId(string $id): bool
    {
        if (\strlen($id) > 128) {
            return false;
        }

        return 1 === preg_match('/^[a-zA-Z0-9._-]+$/', $id);
    }
}
