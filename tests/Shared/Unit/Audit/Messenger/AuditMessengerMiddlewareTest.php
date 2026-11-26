<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Audit\Messenger;

use App\Shared\Infrastructure\Audit\AuditContext;
use App\Shared\Infrastructure\Audit\Messenger\AuditMessengerMiddleware;
use App\Shared\Infrastructure\Audit\Messenger\RequestIdStamp;
use App\Shared\Infrastructure\Audit\Messenger\SessionRefStamp;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

final class AuditMessengerMiddlewareTest extends TestCase
{
    private const string REF = '0193a4b2-6f1e-4c55-9d3e-2a1b0c9d8e7f';

    public function testDispatchStampsTheSessionReference(): void
    {
        $context = new AuditContext();
        $context->setRequestId('rid-1');
        $context->setSessionRef(self::REF);

        $envelope = $this->middleware($context)->handle(new Envelope(new \stdClass()), new StackMiddleware());

        self::assertSame(self::REF, $envelope->last(SessionRefStamp::class)?->getSessionRef());
        self::assertSame('rid-1', $envelope->last(RequestIdStamp::class)?->getRequestId());
    }

    public function testDispatchWithoutSessionReferenceAddsNoStamp(): void
    {
        $envelope = $this->middleware(new AuditContext())->handle(new Envelope(new \stdClass()), new StackMiddleware());

        self::assertNull($envelope->last(SessionRefStamp::class));
    }

    public function testHandlingRestoresTheSessionReferenceAndResetsAfterwards(): void
    {
        $context = new AuditContext();
        $handler = new class($context) implements MiddlewareInterface {
            public ?string $seen = null;

            public function __construct(private readonly AuditContext $context)
            {
            }

            #[\Override]
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                $this->seen = $this->context->getSessionRef();

                return $envelope;
            }
        };
        $stack = new StackMiddleware($handler);

        $envelope = new Envelope(new \stdClass(), [
            new ReceivedStamp('async'),
            new RequestIdStamp('rid-2'),
            new SessionRefStamp(self::REF),
        ]);
        $this->middleware($context)->handle($envelope, $stack);

        self::assertSame(self::REF, $handler->seen);
        self::assertNull($context->getSessionRef());
    }

    private function middleware(AuditContext $context): AuditMessengerMiddleware
    {
        return new AuditMessengerMiddleware($context, new TokenStorage(), $this->createStub(EntityManagerInterface::class));
    }
}
