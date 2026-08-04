<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Infrastructure\Mail\Messenger;

use App\Shared\Infrastructure\Mail\Messenger\SkipPermanentSmtpRetryMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;

final class SkipPermanentSmtpRetryMiddlewareTest extends TestCase
{
    public function testRethrowsPermanentSmtp5xxAsUnrecoverable(): void
    {
        $smtp = new UnexpectedResponseException('Expected 250 got 554', 554);
        $middleware = new SkipPermanentSmtpRetryMiddleware();

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('Permanent SMTP rejection (code 554)');

        $middleware->handle(new Envelope(new \stdClass()), $this->stackThatThrows($smtp));
    }

    public function testUnwrapsHandlerFailedException(): void
    {
        $smtp = new UnexpectedResponseException('spam rejected', 554);
        $handlerFailed = new HandlerFailedException(new Envelope(new \stdClass()), [$smtp]);
        $middleware = new SkipPermanentSmtpRetryMiddleware();

        try {
            $middleware->handle(new Envelope(new \stdClass()), $this->stackThatThrows($handlerFailed));
            self::fail('Expected UnrecoverableMessageHandlingException');
        } catch (UnrecoverableMessageHandlingException $e) {
            self::assertSame(554, $e->getCode());
            self::assertSame($handlerFailed, $e->getPrevious());
        }
    }

    public function testDoesNotConvertSmtp4xx(): void
    {
        $smtp = new UnexpectedResponseException('mailbox unavailable', 450);
        $middleware = new SkipPermanentSmtpRetryMiddleware();

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionCode(450);

        $middleware->handle(new Envelope(new \stdClass()), $this->stackThatThrows($smtp));
    }

    public function testPassesThroughOtherExceptions(): void
    {
        $middleware = new SkipPermanentSmtpRetryMiddleware();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $middleware->handle(new Envelope(new \stdClass()), $this->stackThatThrows(new \RuntimeException('boom')));
    }

    public function testReturnsEnvelopeOnSuccess(): void
    {
        $envelope = new Envelope(new \stdClass());
        $middleware = new SkipPermanentSmtpRetryMiddleware();

        $result = $middleware->handle($envelope, new StackMiddleware());

        self::assertSame($envelope, $result);
    }

    private function stackThatThrows(\Throwable $exception): StackInterface
    {
        $inner = new readonly class($exception) implements \Symfony\Component\Messenger\Middleware\MiddlewareInterface {
            public function __construct(private \Throwable $exception)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                throw $this->exception;
            }
        };

        return new StackMiddleware($inner);
    }
}
