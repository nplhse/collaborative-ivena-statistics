<?php

declare(strict_types=1);

namespace App\Tests\Support\RateLimit;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Installs a pre-exhausted limiter service so Functional tests can hit reject paths
 * without relying on low when@test limits (which poison suites).
 *
 * @mixin KernelTestCase
 */
trait DeniesRateLimiter
{
    private function denyRateLimiter(string $serviceId, string $bucketKey): void
    {
        $factory = new RateLimiterFactory([
            'id' => $serviceId.'_deny',
            'policy' => 'fixed_window',
            'limit' => 1,
            'interval' => '1 hour',
        ], new InMemoryStorage());

        self::getContainer()->set($serviceId, $factory);
        $factory->create($bucketKey)->consume(1);
    }

    private function ipRateLimitKey(string $bucket, string $ip = '127.0.0.1'): string
    {
        return sprintf('%s_%s', $bucket, sha1($ip));
    }

    private function userAndIpRateLimitKey(string $bucket, string|int $userId, string $ip = '127.0.0.1'): string
    {
        return sprintf('%s_%s_%s', $bucket, sha1((string) $userId), sha1($ip));
    }
}
