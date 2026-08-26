<?php

declare(strict_types=1);

namespace App\Analytics\Infrastructure\Http;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class AnalyticsCookieManager
{
    public const string VISITOR_COOKIE = 'analytics_visitor';

    public const string SESSION_COOKIE = 'analytics_session';

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

        $visitor = $this->readCookie($request, self::VISITOR_COOKIE);
        $session = $this->readCookie($request, self::SESSION_COOKIE);
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
            $this->clearCookie($request, $response, self::VISITOR_COOKIE);
            $this->clearCookie($request, $response, self::SESSION_COOKIE);

            return;
        }

        $keys = $this->resolveKeys($request, true);

        if (null !== $keys['setVisitor']) {
            $response->headers->setCookie(
                Cookie::create(self::VISITOR_COOKIE, $keys['setVisitor'])
                    ->withPath('/')
                    ->withSecure($request->isSecure())
                    ->withHttpOnly(true)
                    ->withSameSite(Cookie::SAMESITE_LAX)
                    ->withExpires(new \DateTimeImmutable('+1 year')),
            );
            $request->cookies->set(self::VISITOR_COOKIE, $keys['setVisitor']);
        }

        if (null !== $keys['setSession']) {
            $response->headers->setCookie(
                Cookie::create(self::SESSION_COOKIE, $keys['setSession'])
                    ->withPath('/')
                    ->withSecure($request->isSecure())
                    ->withHttpOnly(true)
                    ->withSameSite(Cookie::SAMESITE_LAX),
            );
            $request->cookies->set(self::SESSION_COOKIE, $keys['setSession']);
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
