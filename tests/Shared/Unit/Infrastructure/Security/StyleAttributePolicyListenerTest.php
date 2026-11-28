<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Infrastructure\Security;

use App\Shared\Infrastructure\Security\StyleAttributePolicyListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(StyleAttributePolicyListener::class)]
final class StyleAttributePolicyListenerTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function headers(): iterable
    {
        yield 'report-only' => ['Content-Security-Policy-Report-Only'];
        yield 'enforced' => ['Content-Security-Policy'];
    }

    #[DataProvider('headers')]
    public function testAppendsTheStyleAttributeDirective(string $header): void
    {
        $response = new Response(headers: [$header => "default-src 'self'; style-src 'self' 'nonce-abc'"]);

        $this->dispatch($response);

        self::assertSame("default-src 'self'; style-src 'self' 'nonce-abc'; style-src-attr 'unsafe-inline'", $response->headers->get($header));
    }

    public function testLeavesResponsesWithoutPolicyAlone(): void
    {
        $response = new Response();

        $this->dispatch($response);

        self::assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function testDoesNotAddTheDirectiveTwice(): void
    {
        $response = new Response(headers: ['Content-Security-Policy' => "default-src 'self'; style-src-attr 'none'"]);

        $this->dispatch($response);

        self::assertSame("default-src 'self'; style-src-attr 'none'", $response->headers->get('Content-Security-Policy'));
    }

    private function dispatch(Response $response): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        new StyleAttributePolicyListener()(new ResponseEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST, $response));
    }
}
