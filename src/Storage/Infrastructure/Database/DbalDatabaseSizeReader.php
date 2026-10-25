<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure\Database;

use App\Storage\Application\Contract\DatabaseSizeReaderInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * pg_database_size() of the current database. Other platforms are not supported and report a failure,
 * so the measurement marks the database as unavailable instead of counting 0.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for DatabaseSizeReaderInterface.
 */
#[AsAlias(DatabaseSizeReaderInterface::class)]
final readonly class DbalDatabaseSizeReader implements DatabaseSizeReaderInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function bytes(): int
    {
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            throw new \RuntimeException('Database size is only supported on PostgreSQL.');
        }

        $bytes = $this->connection->fetchOne('SELECT pg_database_size(current_database())');
        if (!is_numeric($bytes)) {
            throw new \RuntimeException('PostgreSQL did not report a database size.');
        }

        return (int) $bytes;
    }
}
