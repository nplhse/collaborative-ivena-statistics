<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Audit\Http;

use App\Shared\Infrastructure\Audit\AuditContext;
use App\Shared\Infrastructure\Audit\Http\AuditRequestSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class AuditRequestSubscriberTest extends TestCase
{
    #[DataProvider('headerCases')]
    public function testRequestIdIsAlwaysGeneratedAndValidHeaderIsKeptAsUpstream(?string $header, ?string $expectedUpstream): void
    {
        $context = new AuditContext();
        $subscriber = new AuditRequestSubscriber($context, $this->createStub(Security::class));
        $request = Request::create('/');
        if (null !== $header) {
            $request->headers->set('X-Request-Id', $header);
        }

        $subscriber->onKernelRequest($this->requestEvent($request));

        $requestId = $context->getRequestId();
        self::assertNotNull($requestId);
        self::assertInstanceOf(UuidV7::class, Uuid::fromString($requestId));
        self::assertNotSame($header, $requestId);
        self::assertSame($expectedUpstream, $context->getUpstreamRequestId());
        self::assertSame(AuditContext::ORIGIN_HTTP, $context->getOrigin());
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function headerCases(): iterable
    {
        yield 'no header' => [null, null];
        yield 'valid header' => ['proxy-req.42_A', 'proxy-req.42_A'];
        yield 'uuid header from a client is not trusted as request id' => ['0193a4b2-6f1e-7c55-9d3e-2a1b0c9d8e7f', '0193a4b2-6f1e-7c55-9d3e-2a1b0c9d8e7f'];
        yield 'invalid characters' => ['bad id;drop', null];
        yield 'too long' => [str_repeat('a', 129), null];
    }

    public function testResponseCarriesTheGeneratedId(): void
    {
        $context = new AuditContext();
        $subscriber = new AuditRequestSubscriber($context, $this->createStub(Security::class));
        $request = Request::create('/');
        $request->headers->set('X-Request-Id', 'proxy-1');
        $subscriber->onKernelRequest($this->requestEvent($request));

        $response = new Response();
        $subscriber->onKernelResponse(new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        ));

        self::assertSame($context->getRequestId(), $response->headers->get('X-Request-Id'));
    }

    private function requestEvent(Request $request): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
