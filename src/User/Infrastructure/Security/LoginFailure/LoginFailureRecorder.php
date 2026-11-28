<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security\LoginFailure;

use App\User\Application\Contract\LoginFailureRecorderInterface;
use App\User\Domain\Enum\LoginFailureKind;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writes one row to `login_failure_event` (#1487). Username and IP are stored as HMAC-SHA256 with the app secret, so
 * the same value always gives the same hash but cannot be read back. A failed write is logged and never changes the
 * answer of the sign-in.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for LoginFailureRecorderInterface.
 */
#[AsAlias(LoginFailureRecorderInterface::class)]
final readonly class LoginFailureRecorder implements LoginFailureRecorderInterface
{
    /** @psalm-suppress PossiblyUnusedMethod Built by the container. */
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        #[Autowire(param: 'kernel.secret')]
        private string $secret,
        #[Autowire(service: 'monolog.logger.security')]
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function record(LoginFailureKind $kind, ?string $identifier, ?string $ip): void
    {
        try {
            $this->connection->insert('login_failure_event', [
                'occurred_at' => $this->clock->now(),
                'kind' => $kind->value,
                'identifier_hash' => $this->hash('identifier', null !== $identifier ? mb_strtolower(trim($identifier)) : null),
                'ip_hash' => $this->hash('ip', $ip),
            ], [
                'occurred_at' => Types::DATETIME_IMMUTABLE,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error('security.login.failure_record_failed', [
                'kind' => $kind->value,
                'exception' => $exception::class,
            ]);
        }
    }

    private function hash(string $purpose, ?string $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return hash_hmac('sha256', $purpose.':'.$value, $this->secret);
    }
}
