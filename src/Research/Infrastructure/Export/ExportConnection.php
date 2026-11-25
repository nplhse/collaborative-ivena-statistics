<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Export;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The export connection of data extract requests (ADR 045). Without RESEARCH_DATABASE_URL there is none: query checks,
 * dry runs and exports stay disabled, and nothing falls back to the application user.
 */
final readonly class ExportConnection
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private ManagerRegistry $registry,
        #[Autowire(env: 'default::RESEARCH_DATABASE_URL')]
        private ?string $url,
    ) {
    }

    public function isConfigured(): bool
    {
        return null !== $this->url && '' !== trim($this->url);
    }

    public function get(): ?Connection
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $connection = $this->registry->getConnection('research');

        return $connection instanceof Connection ? $connection : null;
    }

    /**
     * Runs $work in a read-only transaction with a statement timeout, REPEATABLE READ so that all queries of one export
     * see the same data, and always rolls back. Inside an outer transaction (the test database wrapper) it becomes a
     * read-only savepoint.
     *
     * @template T
     *
     * @param callable(Connection): T $work
     *
     * @return T
     */
    public static function readOnly(Connection $connection, int $timeoutSeconds, callable $work): mixed
    {
        if (!$connection->isTransactionActive()) {
            $connection->setTransactionIsolation(TransactionIsolationLevel::REPEATABLE_READ);
        }

        $connection->beginTransaction();
        try {
            $connection->executeStatement('SET TRANSACTION READ ONLY');
            $connection->executeStatement(\sprintf("SET LOCAL statement_timeout = '%ds'", $timeoutSeconds));

            return $work($connection);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
