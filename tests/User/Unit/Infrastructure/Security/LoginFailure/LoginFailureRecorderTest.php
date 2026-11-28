<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Infrastructure\Security\LoginFailure;

use App\User\Domain\Enum\LoginFailureKind;
use App\User\Infrastructure\Security\LoginFailure\LoginFailureRecorder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;

#[CoversClass(LoginFailureRecorder::class)]
final class LoginFailureRecorderTest extends TestCase
{
    public function testAFailedWriteIsLoggedAndDoesNotFailTheSignIn(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('insert')->willThrowException(new \RuntimeException('database gone'));

        $logged = [];
        $logger = new class(function (string $message, array $context) use (&$logged): void {
            $logged[] = [$message, $context];
        }) extends AbstractLogger {
            /** @param \Closure(string, array<array-key, mixed>): void $record */
            public function __construct(private readonly \Closure $record)
            {
            }

            #[\Override]
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                ($this->record)((string) $message, $context);
            }
        };

        new LoginFailureRecorder($connection, new MockClock(), 'test-secret', $logger)
            ->record(LoginFailureKind::Failure, 'alice', '192.0.2.1');

        self::assertSame([['security.login.failure_record_failed', ['kind' => 'failure', 'exception' => \RuntimeException::class]]], $logged);
    }
}
