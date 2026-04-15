<?php

declare(strict_types=1);

namespace App\Shared\UI\Http\Controller;

use App\Shared\Infrastructure\Consent\CookieConsentService;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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

    #[Route('/cookies/banner', name: 'app_cookie_banner', methods: ['POST'])]
    public function banner(Request $request): RedirectResponse
    {
        $payload = $request->request->all();
        $submitted = $payload['cookie_consent_banner'] ?? null;
        if (!\is_array($submitted)) {
            return $this->redirectToRoute('app_default');
        }

        if (!$this->isCsrfTokenValid('cookie_consent_banner', (string) ($submitted['_token'] ?? ''))) {
            return $this->redirectToRoute('app_default');
        }

        $monitoring = \array_key_exists('all', $submitted);

        $consent = $this->cookieConsentService->resolveForRequest($request, $this->currentUser());
        $this->cookieConsentService->applyPreference($consent, $monitoring, $this->currentUser());

        $target = $this->resolveSafeLocalPath((string) ($submitted['target'] ?? '/'));

        return $this->redirect($target);
    }

    #[Route('/cookies/preferences', name: 'app_cookie_preferences', methods: ['GET'])]
    public function preferences(Request $request): Response
    {
        $consent = $this->cookieConsentService->resolveForRequest($request, $this->currentUser());

        return $this->render('@Shared/cookies/preferences.html.twig', [
            'consent' => $this->cookieConsentService->describe($consent),
        ]);
    }

    #[Route('/cookies/preferences', name: 'app_cookie_preferences_save', methods: ['POST'])]
    public function savePreferences(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('cookie_consent', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('flash.security.invalid_csrf');
        }

        $consent = $this->cookieConsentService->resolveForRequest($request, $this->currentUser());
        $monitoring = $request->request->has('monitoring');
        $this->cookieConsentService->applyPreference($consent, $monitoring, $this->currentUser());
        $this->addFlash('success', 'flash.cookies.updated');

        return $this->redirectToRoute('app_cookie_preferences');
    }

    private function resolveSafeLocalPath(string $target): string
    {
        $target = trim($target);
        if ('' === $target || !str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return '/';
        }

        return $target;
    }

    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
