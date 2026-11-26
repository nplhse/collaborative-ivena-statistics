<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure\Backup;

use App\Storage\Application\Backup\BackupFailed;
use App\Storage\Application\Backup\BackupFormat;
use App\Storage\Application\Contract\DatabaseRestorerInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Replaces schema public with a dump (ADR 038, "Restore").
 *
 * clear() drops schema public and creates an empty one, as bin/ops/restore-database.sh does. stash() renames it
 * (`pre_restore_<time>`) instead and creates an empty one, so a rollback is possible; both run in one
 * transaction. pg_restore then restores the dump's entries without PostGIS objects and without the entry that
 * creates schema public, as bin/ops/restore-database.sh does. A rollback drops the new schema and renames the old
 * one back; after success the old schema is dropped.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for DatabaseRestorerInterface.
 */
#[AsAlias(DatabaseRestorerInterface::class)]
final readonly class PostgresDatabaseRestorer implements DatabaseRestorerInterface
{
    private const string STASH_PREFIX = 'pre_restore_';

    /** TOC entries that are not restored: extensions, PostGIS tables and schema public (created by stash()). */
    private const string TOC_EXCLUDE = '/EXTENSION|spatial_ref_sys|geometry_columns|geography_columns|^\d+; \d+ \d+ SCHEMA - public( |$)/';

    private const string FAILED_QUEUE = 'failed';

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
        $version = $this->tools->version('pg_restore');

        if ($this->connection->isTransactionActive()) {
            throw new BackupFailed('A database transaction is already open.');
        }

        try {
            /** @var array<string, int|string> $queues */
            $queues = $this->connection->fetchAllKeyValue(
                'SELECT queue_name, COUNT(*) FROM messenger_messages WHERE queue_name <> :failed GROUP BY queue_name ORDER BY queue_name',
                ['failed' => self::FAILED_QUEUE],
            );
        } catch (TableNotFoundException) {
            $queues = [];
        } catch (\Throwable $exception) {
            throw new BackupFailed('The database is not reachable.', 'Check DATABASE_URL and that PostgreSQL is running.', previous: $exception);
        }

        if ([] !== $queues) {
            throw new BackupFailed(sprintf('The message queues still hold work: %s.', implode(', ', array_map(static fn (string $queue, int|string $count): string => sprintf('%s (%d)', $queue, (int) $count), array_keys($queues), $queues))), 'Let the worker finish, then stop it ("systemctl --user stop messenger") and run the restore again. Messages in "failed" do not count.');
        }

        return $version;
    }

    #[\Override]
    public function clear(): void
    {
        $this->transactional(function (): void {
            $this->connection->executeStatement('DROP SCHEMA public CASCADE');
            $this->createPublicSchema();
        }, 'The current schema could not be dropped.');
    }

    #[\Override]
    public function stash(string $suffix): string
    {
        $stash = self::STASH_PREFIX.$suffix;
        if (1 !== preg_match('/^[a-z0-9_]+$/', $stash)) {
            throw new BackupFailed(sprintf('"%s" is not a valid schema name.', $stash));
        }

        if (false !== $this->connection->fetchOne('SELECT 1 FROM pg_namespace WHERE nspname = ?', [$stash])) {
            throw new BackupFailed(sprintf('The schema %s already exists.', $stash), 'It is left over from an earlier restore; drop it or rename it to "public" by hand.');
        }

        $this->transactional(function () use ($stash): void {
            $this->connection->executeStatement(sprintf('ALTER SCHEMA public RENAME TO %s', $stash));
            $this->createPublicSchema();
        }, 'The current schema could not be moved aside.');

        return $stash;
    }

    #[\Override]
    public function restore(string $dumpFile): void
    {
        // Rows of runtime tables are skipped as well, for backups made before they were left out of the dump (#1452).
        $runtimeData = '/^\d+; \d+ \d+ TABLE DATA public (?:'.implode('|', array_map(static fn (string $table): string => preg_quote($table, '/'), BackupFormat::RUNTIME_TABLES)).') /';
        $entries = array_filter(
            explode("\n", $this->tools->listDump($dumpFile)),
            static fn (string $line): bool => 1 !== preg_match(self::TOC_EXCLUDE, $line) && 1 !== preg_match($runtimeData, $line),
        );

        $this->tools->restore($dumpFile, implode("\n", $entries)."\n");
    }

    #[\Override]
    public function tableRows(): array
    {
        return PublicTableRows::count($this->connection);
    }

    #[\Override]
    public function rollback(string $stash): void
    {
        $this->transactional(function () use ($stash): void {
            $this->connection->executeStatement('DROP SCHEMA public CASCADE');
            $this->connection->executeStatement(sprintf('ALTER SCHEMA %s RENAME TO public', $this->connection->quoteSingleIdentifier($stash)));
        }, 'The previous schema could not be moved back.');
    }

    #[\Override]
    public function discard(string $stash): void
    {
        $this->transactional(function () use ($stash): void {
            $this->connection->executeStatement(sprintf('DROP SCHEMA %s CASCADE', $this->connection->quoteSingleIdentifier($stash)));
        }, 'The previous schema could not be dropped.');
    }

    private function createPublicSchema(): void
    {
        $user = (string) $this->connection->fetchOne('SELECT current_user');
        $this->connection->executeStatement('CREATE SCHEMA public');
        $this->connection->executeStatement(sprintf('GRANT ALL ON SCHEMA public TO %s', $this->connection->quoteSingleIdentifier($user)));
        $this->connection->executeStatement('GRANT ALL ON SCHEMA public TO public');
    }

    /**
     * @param callable(): void $statements
     *
     * @throws BackupFailed
     */
    private function transactional(callable $statements, string $message): void
    {
        $this->connection->beginTransaction();
        try {
            $statements();
            $this->connection->commit();
        } catch (\Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw new BackupFailed(sprintf('%s %s', $message, mb_substr($exception->getMessage(), 0, 300)), 'Check that the database user owns schema public.', previous: $exception);
        }
    }
}
