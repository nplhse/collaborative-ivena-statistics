<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Locale;

use App\Shared\Application\Locale\SupportedLocales;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

final readonly class LocaleCookieManager
{
    /** Cookie name without the prefix of app.cookie_prefix (empty except in the demo environment). */
    public const string COOKIE_NAME = 'APP_LOCALE';

    public function __construct(
        private ClockInterface $clock,
        #[Autowire(param: 'app.cookie_prefix')]
        private string $cookiePrefix = '',
    ) {
    }

    public function cookieName(): string
    {
        return $this->cookiePrefix.self::COOKIE_NAME;
    }

    public function readFromRequest(Request $request): ?string
    {
        return SupportedLocales::normalize($request->cookies->getString($this->cookieName()));
    }

    public function buildCookie(Request $request, string $locale): Cookie
    {
        return Cookie::create($this->cookieName(), $locale)
            ->withPath('/')
            ->withSecure($request->isSecure())
            ->withHttpOnly(false)
            ->withSameSite(Cookie::SAMESITE_LAX)
            ->withExpires($this->clock->now()->modify('+1 year'));
    }
}
