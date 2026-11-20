<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure\Backup;

use App\Storage\Application\Backup\BackupFailed;
use App\Storage\Application\Backup\DatabaseDump;
use App\Storage\Application\Contract\DatabaseDumperInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Dumps schema public with pg_dump from an exported snapshot (ADR 038, "Consistency").
 *
 * A read-only REPEATABLE READ transaction exports its snapshot with pg_export_snapshot(); pg_dump reads exactly
 * that snapshot (--snapshot), and the row counts and executed migrations are read inside the same transaction.
 * Dump and manifest therefore describe the same state, while the application keeps running.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for DatabaseDumperInterface.
 */
#[AsAlias(DatabaseDumperInterface::class)]
final readonly class PostgresDatabaseDumper implements DatabaseDumperInterface
{
    private const string MIGRATIONS_TABLE = 'doctrine_migration_versions';

    public function __construct(
        private Connection $connection,
        private PostgresTools $tools,
    ) {
    }

    #[\Override]
    public function target(): string
    {
        return $this->tools->target();
    }

    #[\Override]
    public function preflight(): string
    {
        $version = $this->tools->version('pg_dump');

        try {
            $this->connection->fetchOne('SELECT 1');
        } catch (\Throwable $exception) {
            throw new BackupFailed('The database is not reachable.', 'Check DATABASE_URL and that PostgreSQL is running.', previous: $exception);
        }

        return $version;
    }

    #[\Override]
    public function dump(string $targetFile): DatabaseDump
    {
        if ($this->connection->isTransactionActive()) {
            throw new BackupFailed('A database transaction is already open; the snapshot would not be consistent.');
        }

        $toolVersion = $this->tools->version('pg_dump');

        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $snapshot = (string) $this->connection->fetchOne('SELECT pg_export_snapshot()');
            $serverVersion = (string) $this->connection->fetchOne('SHOW server_version');
            $tableRows = $this->tableRows();
            $migrations = \array_key_exists(self::MIGRATIONS_TABLE, $tableRows) ? $this->executedMigrations() : [];

            $this->tools->dump($snapshot, $targetFile);
        } finally {
            $this->connection->rollBack();
        }

        if (!is_file($targetFile) || 0 === filesize($targetFile)) {
            throw new BackupFailed('pg_dump wrote no data.', 'Run the command again with -vvv for details.');
        }

        return new DatabaseDump($serverVersion, $toolVersion, $migrations, $tableRows);
    }

    /**
     * @return array<string, int>
     */
    private function tableRows(): array
    {
        /** @var list<string> $tables */
        $tables = $this->connection->fetchFirstColumn(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' AND table_name NOT IN (?) ORDER BY table_name",
            [PostgresTools::POSTGIS_TABLES],
            [ArrayParameterType::STRING],
        );

        $rows = [];
        foreach ($tables as $table) {
            $rows[$table] = (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM public.%s', $this->connection->quoteSingleIdentifier($table)));
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function executedMigrations(): array
    {
        // Only called when the table exists: an error would abort the transaction and with it the snapshot.
        /** @var list<string> $versions */
        $versions = $this->connection->fetchFirstColumn(sprintf('SELECT version FROM %s', self::MIGRATIONS_TABLE));

        usort($versions, static fn (string $a, string $b): int => [self::timestamp($a), $a] <=> [self::timestamp($b), $b]);

        return $versions;
    }

    /**
     * Migrations are ordered by the timestamp in their class name across namespaces (ADR 037).
     */
    private static function timestamp(string $version): string
    {
        return 1 === preg_match('/Version(\d+)/', $version, $match) ? $match[1] : '';
    }
}
