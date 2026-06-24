<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Consent;

use App\User\Domain\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/** @psalm-suppress UnusedClass */
final readonly class CookieConsentRequestSubscriber
{
    public function __construct(
        private CookieConsentService $cookieConsentService,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 28)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->wantsConsentContext($request)) {
            return;
        }

        $user = $this->currentUser();
        $consent = $this->cookieConsentService->resolveForRequest($request, $user);
        $request->attributes->set('cookie_consent', $this->cookieConsentService->describe($consent));
    }

    private function wantsConsentContext(Request $request): bool
    {
        if ($request->isXmlHttpRequest()) {
            return false;
        }

        $path = $request->getPathInfo();

        return !str_starts_with($path, '/_');
    }

    private function currentUser(): ?User
    {
        $token = $this->tokenStorage->getToken();
        if (!$token instanceof \Symfony\Component\Security\Core\Authentication\Token\TokenInterface) {
            return null;
        }

        $user = $token->getUser();

        return $user instanceof User ? $user : null;
    }
}
