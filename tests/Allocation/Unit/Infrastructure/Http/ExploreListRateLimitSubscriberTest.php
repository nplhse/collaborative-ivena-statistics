<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Infrastructure\Http;

use App\Allocation\Infrastructure\Http\ExploreListRateLimitSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class ExploreListRateLimitSubscriberTest extends TestCase
{
    public function testIgnoresNonExplorePaths(): void
    {
        $subscriber = new ExploreListRateLimitSubscriber($this->createFactory(), $this->createStub(TokenStorageInterface::class));
        $event = $this->createGetRequestEvent('/statistics/');

        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testIgnoresNonGetMethods(): void
    {
        $subscriber = new ExploreListRateLimitSubscriber($this->createFactory(), $this->createStub(TokenStorageInterface::class));
        $event = $this->createRequestEvent(Request::create('/explore/allocation', Request::METHOD_POST));

        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testReturnsTooManyRequestsWhenLimitExceeded(): void
    {
        $user = $this->createStub(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn('participant-1');

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $factory = $this->createFactory(limit: 3);
        $subscriber = new ExploreListRateLimitSubscriber($factory, $tokenStorage);

        for ($i = 0; $i < 3; ++$i) {
            $event = $this->createGetRequestEvent('/explore/indication');
            $subscriber->onKernelRequest($event);
            self::assertNull($event->getResponse());
        }

        $event = $this->createGetRequestEvent('/explore/indication');
        $subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
    }

    public function testAllowsRequestWhenLimitAccepted(): void
    {
        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        $subscriber = new ExploreListRateLimitSubscriber($this->createFactory(limit: 3), $tokenStorage);
        $event = $this->createGetRequestEvent('/explore/allocation/019fa883-a780-7929-bf8b-2392e0ae1565');

        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    private function createFactory(int $limit = 3): RateLimiterFactory
    {
        return new RateLimiterFactory(
            [
                'id' => 'explore_list_test',
                'policy' => 'fixed_window',
                'limit' => $limit,
                'interval' => '1 hour',
            ],
            new InMemoryStorage(),
        );
    }

    private function createGetRequestEvent(string $path): RequestEvent
    {
        return $this->createRequestEvent(Request::create($path, Request::METHOD_GET));
    }

    private function createRequestEvent(Request $request): RequestEvent
    {
        /** @var HttpKernelInterface&MockObject $kernel */
        $kernel = $this->createStub(HttpKernelInterface::class);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
