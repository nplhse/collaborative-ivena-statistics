<?php

declare(strict_types=1);

namespace App\Shared\Application\Locale;

use App\Shared\Infrastructure\Locale\LocaleCookieManager;
use App\User\Domain\Entity\User;
use Symfony\Component\HttpFoundation\Request;

final readonly class LocaleResolver
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private LocaleCookieManager $localeCookieManager,
    ) {
    }

    public function resolve(Request $request, ?User $user): string
    {
        $userLocale = $this->resolveFromUser($user);
        if (null !== $userLocale) {
            return $userLocale;
        }

        $cookieLocale = $this->localeCookieManager->readFromRequest($request);
        if (null !== $cookieLocale) {
            return $cookieLocale;
        }

        return $this->resolveFromAcceptLanguage($request->headers->get('Accept-Language'));
    }

    public function resolveAutomaticDefault(Request $request): string
    {
        $cookieLocale = $this->localeCookieManager->readFromRequest($request);
        if (null !== $cookieLocale) {
            return $cookieLocale;
        }

        return $this->resolveFromAcceptLanguage($request->headers->get('Accept-Language'));
    }

    public function resolveForUser(?User $user): string
    {
        return $this->resolveFromUser($user) ?? SupportedLocales::DEFAULT;
    }

    private function resolveFromUser(?User $user): ?string
    {
        if (!$user instanceof User || !$user->hasExplicitLocale()) {
            return null;
        }

        return SupportedLocales::normalize($user->getLocale());
    }

    private function resolveFromAcceptLanguage(?string $acceptLanguage): string
    {
        if (null === $acceptLanguage || '' === $acceptLanguage) {
            return SupportedLocales::DEFAULT;
        }

        foreach (explode(',', $acceptLanguage) as $part) {
            $tag = strtolower(trim(explode(';', $part)[0]));

            if ('' === $tag) {
                continue;
            }

            if (str_starts_with($tag, SupportedLocales::GERMAN)) {
                return SupportedLocales::GERMAN;
            }
        }

        return SupportedLocales::DEFAULT;
    }
}
