<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit\Http;

use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Event\AuthenticationTokenCreatedEvent;
use Symfony\Component\Uid\Uuid;

/**
 * Keeps a random reference per authenticated session in the server-side session and hands it to the audit
 * context (ADR 025). The reference is not the session id and never leaves the server.
 *
 * - A login of a different user (or without a previous login) starts a new reference.
 * - A re-authentication of the same user (password confirmation) keeps it.
 * - Sessions that were authenticated before the reference existed get one on their next request.
 *
 * @psalm-suppress UnusedClass
 */
final readonly class AuditSessionRefSubscriber
{
    public const string SESSION_ATTRIBUTE = '_audit_session_ref';

    public function __construct(
        private AuditContext $auditContext,
        private Security $security,
        private RequestStack $requestStack,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    /**
     * Runs when an authentication token is created, before the authenticator's onAuthenticationSuccess() and
     * before LoginSuccessEvent. Entries written while handling the login (for example linking the cookie
     * consent) then already carry the new reference.
     */
    #[AsEventListener]
    public function onTokenCreated(AuthenticationTokenCreatedEvent $event): void
    {
        $request = $this->requestStack->getMainRequest();
        if (!$request instanceof Request || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $current = $session->get(self::SESSION_ATTRIBUTE);
        // The token storage still holds the previous token here; the new one is set right after this event.
        $previousUser = $this->tokenStorage->getToken()?->getUserIdentifier();
        $sameUser = null !== $previousUser && $previousUser === $event->getAuthenticatedToken()->getUserIdentifier();

        $ref = $sameUser && $this->isValidRef($current) ? $current : Uuid::v4()->toRfc4122();
        $session->set(self::SESSION_ATTRIBUTE, $ref);
        $this->auditContext->setSessionRef($ref);
    }

    /**
     * Runs after the firewall (priority 8), so the user of the session is known.
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        // Never start a session for anonymous visitors.
        if (!$request->hasPreviousSession()) {
            return;
        }

        if (!$this->security->getUser() instanceof User) {
            return;
        }

        $this->auditContext->setSessionRef($this->resolveRef($request));
    }

    private function resolveRef(Request $request): string
    {
        $session = $request->getSession();
        $ref = $session->get(self::SESSION_ATTRIBUTE);
        if ($this->isValidRef($ref)) {
            return $ref;
        }

        $ref = Uuid::v4()->toRfc4122();
        $session->set(self::SESSION_ATTRIBUTE, $ref);

        return $ref;
    }

    /**
     * @psalm-assert-if-true string $ref
     */
    private function isValidRef(mixed $ref): bool
    {
        return \is_string($ref) && Uuid::isValid($ref);
    }
}
