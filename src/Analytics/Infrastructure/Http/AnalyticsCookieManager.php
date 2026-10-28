<?php

declare(strict_types=1);

namespace App\Analytics\Infrastructure\Http;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final readonly class AnalyticsCookieManager
{
    /** Cookie names without the prefix of app.cookie_prefix (empty except in the demo environment). */
    public const string VISITOR_COOKIE = 'analytics_visitor';

    public const string SESSION_COOKIE = 'analytics_session';

    public function __construct(
        private ClockInterface $clock,
        #[Autowire(param: 'app.cookie_prefix')]
        private string $cookiePrefix = '',
    ) {
    }

    /**
     * @return array{visitorKey: ?string, sessionKey: ?string, setVisitor: ?string, setSession: ?string}
     */
    public function resolveKeys(Request $request, bool $analyticsConsent): array
    {
        if (!$analyticsConsent) {
            return [
                'visitorKey' => null,
                'sessionKey' => null,
                'setVisitor' => null,
                'setSession' => null,
            ];
        }

        $visitor = $this->readCookie($request, $this->cookiePrefix.self::VISITOR_COOKIE);
        $session = $this->readCookie($request, $this->cookiePrefix.self::SESSION_COOKIE);
        $setVisitor = null;
        $setSession = null;

        if (null === $visitor) {
            $visitor = Uuid::v4()->toRfc4122();
            $setVisitor = $visitor;
        }

        if (null === $session) {
            $session = Uuid::v4()->toRfc4122();
            $setSession = $session;
        }

        return [
            'visitorKey' => $visitor,
            'sessionKey' => $session,
            'setVisitor' => $setVisitor,
            'setSession' => $setSession,
        ];
    }

    public function applyCookies(Request $request, Response $response, bool $analyticsConsent): void
    {
        if (!$analyticsConsent) {
            $this->clearCookie($request, $response, $this->cookiePrefix.self::VISITOR_COOKIE);
            $this->clearCookie($request, $response, $this->cookiePrefix.self::SESSION_COOKIE);

            return;
        }

        $keys = $this->resolveKeys($request, true);

        if (null !== $keys['setVisitor']) {
            $response->headers->setCookie(
                Cookie::create($this->cookiePrefix.self::VISITOR_COOKIE, $keys['setVisitor'])
                    ->withPath('/')
                    ->withSecure($request->isSecure())
                    ->withHttpOnly(true)
                    ->withSameSite(Cookie::SAMESITE_LAX)
                    ->withExpires($this->clock->now()->modify('+1 year')),
            );
            $request->cookies->set($this->cookiePrefix.self::VISITOR_COOKIE, $keys['setVisitor']);
        }

        if (null !== $keys['setSession']) {
            $response->headers->setCookie(
                Cookie::create($this->cookiePrefix.self::SESSION_COOKIE, $keys['setSession'])
                    ->withPath('/')
                    ->withSecure($request->isSecure())
                    ->withHttpOnly(true)
                    ->withSameSite(Cookie::SAMESITE_LAX),
            );
            $request->cookies->set($this->cookiePrefix.self::SESSION_COOKIE, $keys['setSession']);
        }
    }

    private function readCookie(Request $request, string $name): ?string
    {
        $value = $request->cookies->get($name);
        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' !== $value ? $value : null;
    }

    private function clearCookie(Request $request, Response $response, string $name): void
    {
        if (!$request->cookies->has($name)) {
            return;
        }

        $response->headers->clearCookie($name, '/', null, $request->isSecure(), true, 'lax');
    }
}
