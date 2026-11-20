<?php

declare(strict_types=1);

namespace App\Storage\Application\Contract;

use App\Storage\Application\Backup\BackupFailed;

/**
 * Replaces the application schema with the content of a dump, with a way back (ADR 038, "Restore").
 *
 * Either the current schema is dropped first (clear), or it is moved aside (stash) and later dropped once
 * everything else succeeded (discard) or moved back (rollback). The dump is always restored into a fresh schema.
 */
interface DatabaseRestorerInterface
{
    /**
     * The target database for display, without credentials.
     */
    public function target(): string;

    /**
     * Returns the version of the restore tool; fails when it cannot be run, the database is not reachable or
     * the message queues still hold work.
     *
     * @throws BackupFailed
     */
    public function preflight(): string;

    /**
     * Drops the current schema and creates an empty one.
     *
     * @throws BackupFailed
     */
    public function clear(): void;

    /**
     * Moves the current schema aside and creates an empty one; returns the name of the moved schema.
     *
     * @throws BackupFailed
     */
    public function stash(string $suffix): string;

    /**
     * Restores the dump into the empty schema, in one transaction.
     *
     * @throws BackupFailed
     */
    public function restore(string $dumpFile): void;

    /**
     * Rows per table of the restored schema, by table name.
     *
     * @return array<string, int>
     */
    public function tableRows(): array;

    /**
     * Drops the restored schema and moves the stashed one back.
     *
     * @throws BackupFailed
     */
    public function rollback(string $stash): void;

    /**
     * Drops the stashed schema after a successful restore.
     *
     * @throws BackupFailed
     */
    public function discard(string $stash): void;
}
