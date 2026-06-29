<?php

declare(strict_types=1);

namespace App\Shared\Application\Locale;

use App\Shared\Infrastructure\Locale\LocaleCookieManager;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

final readonly class UserLocalePreferenceUpdater
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LocaleCookieManager $localeCookieManager,
    ) {
    }

    public function update(User $user, string $locale, Request $request): Cookie
    {
        if (!SupportedLocales::isSupported($locale)) {
            throw new \InvalidArgumentException(sprintf('Unsupported locale "%s".', $locale));
        }

        $user->setLocale($locale);
        $this->entityManager->flush();

        return $this->localeCookieManager->buildCookie($request, $locale);
    }
}
