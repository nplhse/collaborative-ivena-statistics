<?php

declare(strict_types=1);

namespace App\Shared\UI\Http\Controller;

use App\Shared\Application\Locale\SupportedLocales;
use App\Shared\Application\Locale\UserLocalePreferenceUpdater;
use App\Shared\Infrastructure\Locale\LocaleCookieManager;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class LocaleSwitchController extends AbstractController
{
    public function __construct(
        private readonly UserLocalePreferenceUpdater $userLocalePreferenceUpdater,
        private readonly LocaleCookieManager $localeCookieManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[Route('/locale/switch/{locale}', name: 'app_locale_switch', requirements: ['locale' => 'en|de'], methods: ['GET'])]
    public function switch(string $locale, Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        if (!SupportedLocales::isSupported($locale)) {
            throw $this->createNotFoundException();
        }

        $response = $this->redirect($this->resolveRedirectTarget($request));

        $user = $this->getUser();
        $cookie = $user instanceof User
            ? $this->userLocalePreferenceUpdater->update($user, $locale, $request)
            : $this->localeCookieManager->buildCookie($request, $locale);

        $response->headers->setCookie($cookie);

        return $response;
    }

    private function resolveRedirectTarget(Request $request): string
    {
        $targetPath = $request->query->getString('_target_path');
        if ('' !== $targetPath && $this->isSafeRedirectTarget($request, $targetPath)) {
            return $targetPath;
        }

        $referer = $request->headers->get('Referer', '');
        if ('' !== $referer && $this->isSafeRedirectTarget($request, $referer)) {
            return $referer;
        }

        $user = $this->getUser();
        if ($user instanceof User) {
            return $this->urlGenerator->generate('app_stats_dashboard');
        }

        return $this->urlGenerator->generate('app_default');
    }

    private function isSafeRedirectTarget(Request $request, string $target): bool
    {
        if (str_starts_with($target, '/')) {
            return !str_starts_with($target, '//');
        }

        $host = $request->getSchemeAndHttpHost();

        return str_starts_with($target, $host.'/') || $target === $host;
    }
}
