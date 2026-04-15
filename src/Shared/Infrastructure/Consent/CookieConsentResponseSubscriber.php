<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Consent;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** @psalm-suppress UnusedClass */
final readonly class CookieConsentResponseSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private CookieConsentService $cookieConsentService,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -128],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $subjectId = $request->attributes->get('cookie_consent_subject_id');
        if (!\is_string($subjectId) || '' === $subjectId) {
            return;
        }

        $response = $event->getResponse();
        $response->headers->setCookie(
            $this->cookieConsentService->buildSubjectCookieForSubjectId($request, $subjectId)
        );
    }
}
