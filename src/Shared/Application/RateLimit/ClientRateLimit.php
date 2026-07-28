<?php

declare(strict_types=1);

namespace App\Shared\Application\RateLimit;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Shared consume helpers for HTTP rate limiters (SEC-007).
 */
final class ClientRateLimit
{
    public static function acceptIp(RateLimiterFactory $factory, string $bucket, Request $request): bool
    {
        $key = sprintf('%s_%s', $bucket, sha1($request->getClientIp() ?? 'unknown'));

        return $factory->create($key)->consume(1)->isAccepted();
    }

    public static function acceptUserAndIp(
        RateLimiterFactory $factory,
        string $bucket,
        string $userIdentifier,
        Request $request,
    ): bool {
        $key = sprintf(
            '%s_%s_%s',
            $bucket,
            sha1($userIdentifier),
            sha1($request->getClientIp() ?? 'unknown'),
        );

        return $factory->create($key)->consume(1)->isAccepted();
    }
}
