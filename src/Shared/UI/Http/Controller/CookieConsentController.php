<?php

declare(strict_types=1);

namespace App\Shared\UI\Http\Controller;

use App\Shared\Infrastructure\Consent\CookieConsentService;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CookieConsentController extends AbstractController
{
    public function __construct(
        private readonly CookieConsentService $cookieConsentService,
    ) {
    }

    #[Route('/cookies/consent/current', name: 'app_cookie_consent_current', methods: ['GET'])]
    public function current(Request $request): JsonResponse
    {
        $consent = $this->cookieConsentService->getOrCreateForRequest($request, $this->currentUser());

        $response = $this->json($this->normalize($consent));
        $response->headers->setCookie($this->cookieConsentService->buildSubjectCookie($request, $consent));

        return $response;
    }

    #[Route('/cookies/consent/update', name: 'app_cookie_consent_update', methods: ['POST'])]
    public function update(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('cookie_consent', (string) $request->request->get('_token'))) {
            return $this->json(['error' => 'invalid_csrf'], Response::HTTP_FORBIDDEN);
        }

        $consent = $this->cookieConsentService->getOrCreateForRequest($request, $this->currentUser());
        $monitoring = filter_var($request->request->get('monitoring'), FILTER_VALIDATE_BOOL);
        $this->cookieConsentService->applyPreference($consent, $monitoring);

        $response = $this->json($this->normalize($consent));
        $response->headers->setCookie($this->cookieConsentService->buildSubjectCookie($request, $consent));

        return $response;
    }

    #[Route('/cookies/preferences', name: 'app_cookie_preferences', methods: ['GET'])]
    public function preferences(Request $request): Response
    {
        $consent = $this->cookieConsentService->getOrCreateForRequest($request, $this->currentUser());

        $response = $this->render('@Shared/cookies/preferences.html.twig', [
            'consent' => $this->normalize($consent),
        ]);
        $response->headers->setCookie($this->cookieConsentService->buildSubjectCookie($request, $consent));

        return $response;
    }

    #[Route('/cookies/preferences', name: 'app_cookie_preferences_save', methods: ['POST'])]
    public function savePreferences(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('cookie_consent', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('flash.security.invalid_csrf');
        }

        $consent = $this->cookieConsentService->getOrCreateForRequest($request, $this->currentUser());
        $monitoring = filter_var($request->request->get('monitoring'), FILTER_VALIDATE_BOOL);
        $this->cookieConsentService->applyPreference($consent, $monitoring);
        $this->addFlash('success', 'flash.cookies.updated');

        $response = $this->redirectToRoute('app_cookie_preferences');
        $response->headers->setCookie($this->cookieConsentService->buildSubjectCookie($request, $consent));

        return $response;
    }

    /**
     * @return array{
     *   decided: bool,
     *   preferences: array{essential: bool, monitoring: bool},
     *   consentVersion: string
     * }
     */
    private function normalize(\App\Shared\Domain\Entity\CookieConsent $consent): array
    {
        return [
            'decided' => $consent->getDecidedAt() instanceof \DateTimeImmutable,
            'preferences' => $consent->getPreferences(),
            'consentVersion' => $consent->getConsentVersion(),
        ];
    }

    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
