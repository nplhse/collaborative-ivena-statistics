<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Locale;

use App\Shared\Application\Locale\SupportedLocales;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

final class LocaleCookieManager
{
    public const string COOKIE_NAME = 'APP_LOCALE';

    public function readFromRequest(Request $request): ?string
    {
        return SupportedLocales::normalize($request->cookies->getString(self::COOKIE_NAME));
    }

    public function buildCookie(Request $request, string $locale): Cookie
    {
        return Cookie::create(self::COOKIE_NAME, $locale)
            ->withPath('/')
            ->withSecure($request->isSecure())
            ->withHttpOnly(false)
            ->withSameSite('lax')
            ->withExpires(new \DateTimeImmutable('+1 year'));
    }
}
