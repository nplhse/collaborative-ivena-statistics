<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Infrastructure\Demo;

use App\Shared\Infrastructure\Demo\DemoEnvironmentGuard;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class DemoEnvironmentGuardTest extends TestCase
{
    private const string DEMO_URL = 'postgresql://app:password@127.0.0.1:5432/app_demo?serverVersion=18&charset=utf8';

    private const string DEV_URL = 'postgresql://app:password@127.0.0.1:5432/app?serverVersion=18&charset=utf8';

    #[DoesNotPerformAssertions]
    public function testLetsCommandsAndRequestsReachALocalDemoDatabase(): void
    {
        $guard = new DemoEnvironmentGuard(self::DEMO_URL);

        $guard->onConsoleCommand();
        $guard->onKernelRequest($this->requestEvent(HttpKernelInterface::MAIN_REQUEST));
    }

    public function testStopsConsoleCommandsOnAnotherDatabase(): void
    {
        $guard = new DemoEnvironmentGuard(self::DEV_URL);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The demo environment refuses this database: database name "app" does not end in "_demo".');

        $guard->onConsoleCommand();
    }

    public function testStopsMainRequestsOnARemoteDatabase(): void
    {
        $guard = new DemoEnvironmentGuard('postgresql://app:password@db.example.org:5432/app_demo?serverVersion=18');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('database host "db.example.org" is not local');

        $guard->onKernelRequest($this->requestEvent(HttpKernelInterface::MAIN_REQUEST));
    }

    #[DoesNotPerformAssertions]
    public function testLeavesSubRequestsToTheMainRequestCheck(): void
    {
        $guard = new DemoEnvironmentGuard(self::DEV_URL);

        $guard->onKernelRequest($this->requestEvent(HttpKernelInterface::SUB_REQUEST));
    }

    private function requestEvent(int $requestType): RequestEvent
    {
        return new RequestEvent(self::createStub(HttpKernelInterface::class), Request::create('/'), $requestType);
    }
}
