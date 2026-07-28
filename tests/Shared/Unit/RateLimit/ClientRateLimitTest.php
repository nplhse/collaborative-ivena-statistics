<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\RateLimit;

use App\Shared\Application\RateLimit\ClientRateLimit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class ClientRateLimitTest extends TestCase
{
    public function testAcceptIpAllowsUntilLimitThenRejects(): void
    {
        $factory = $this->createFactory(limit: 3);
        $request = Request::create('/register', Request::METHOD_POST, server: ['REMOTE_ADDR' => '203.0.113.10']);

        self::assertTrue(ClientRateLimit::acceptIp($factory, 'register', $request));
        self::assertTrue(ClientRateLimit::acceptIp($factory, 'register', $request));
        self::assertTrue(ClientRateLimit::acceptIp($factory, 'register', $request));
        self::assertFalse(ClientRateLimit::acceptIp($factory, 'register', $request));
    }

    public function testAcceptUserAndIpUsesSeparateBucketsPerUser(): void
    {
        $factory = $this->createFactory(limit: 1);
        $request = Request::create('/import/new', Request::METHOD_POST, server: ['REMOTE_ADDR' => '203.0.113.20']);

        self::assertTrue(ClientRateLimit::acceptUserAndIp($factory, 'import_create', '1', $request));
        self::assertFalse(ClientRateLimit::acceptUserAndIp($factory, 'import_create', '1', $request));
        self::assertTrue(ClientRateLimit::acceptUserAndIp($factory, 'import_create', '2', $request));
    }

    private function createFactory(int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory([
            'id' => 'sec007_test',
            'policy' => 'fixed_window',
            'limit' => $limit,
            'interval' => '1 hour',
        ], new InMemoryStorage());
    }
}
