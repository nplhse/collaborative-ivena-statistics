<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Security\LoginFailure;

use App\Tests\User\Contract\LoginFailureRecorderContract;
use App\User\Application\Contract\LoginFailureRecorderInterface;
use App\User\Domain\Enum\LoginFailureKind;
use App\User\Infrastructure\Security\LoginFailure\LoginFailureRecorder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[CoversClass(LoginFailureRecorder::class)]
#[ResetDatabase]
final class LoginFailureRecorderTest extends LoginFailureRecorderContract
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testUsernameAndIpAreStoredOnlyAsHashes(): void
    {
        $this->recorder()->record(LoginFailureKind::Failure, 'alice', '192.0.2.1');

        $row = $this->connection->fetchAssociative('SELECT identifier_hash, ip_hash FROM login_failure_event');

        self::assertSame(['identifier_hash' => hash_hmac('sha256', 'identifier:alice', $this->secret()), 'ip_hash' => hash_hmac('sha256', 'ip:192.0.2.1', $this->secret())], $row);
    }

    public function testAFailedWriteIsLoggedAndDoesNotFailTheSignIn(): void
    {
        $logged = [];
        $logger = new class(function (string $message) use (&$logged): void {
            $logged[] = $message;
        }) extends AbstractLogger {
            /** @param \Closure(string): void $log */
            public function __construct(private readonly \Closure $log)
            {
            }

            #[\Override]
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                ($this->log)((string) $message);
            }
        };
        $this->connection->executeStatement('ALTER TABLE login_failure_event RENAME TO login_failure_event_gone');
        $recorder = new LoginFailureRecorder($this->connection, new MockClock(), 'secret', $logger);

        $recorder->record(LoginFailureKind::Failure, 'alice', '192.0.2.1');

        self::assertSame(['security.login.failure_record_failed'], $logged);
    }

    #[\Override]
    protected function recorder(): LoginFailureRecorderInterface
    {
        return self::getContainer()->get(LoginFailureRecorderInterface::class);
    }

    #[\Override]
    protected function recordedKinds(): array
    {
        /* @var list<string> */
        return $this->connection->fetchFirstColumn('SELECT kind FROM login_failure_event ORDER BY id');
    }

    #[\Override]
    protected function recordedIdentifiers(): array
    {
        /* @var list<?string> */
        return $this->connection->fetchFirstColumn('SELECT identifier_hash FROM login_failure_event ORDER BY id');
    }

    private function secret(): string
    {
        $secret = self::getContainer()->getParameter('kernel.secret');
        self::assertIsString($secret);

        return $secret;
    }
}
