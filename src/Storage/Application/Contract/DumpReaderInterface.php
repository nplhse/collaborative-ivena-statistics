<?php

declare(strict_types=1);

namespace App\Storage\Application\Contract;

use App\Storage\Application\Backup\BackupFailed;

/**
 * Reads the table of contents of a database dump without restoring it (ADR 038, "Verify").
 */
interface DumpReaderInterface
{
    /**
     * Returns the version of the restore tool; fails when it cannot be run.
     *
     * @throws BackupFailed
     */
    public function preflight(): string;

    /**
     * The tables of schema public whose data the dump contains.
     *
     * @return list<string>
     *
     * @throws BackupFailed when the dump cannot be read
     */
    public function tables(string $dumpFile): array;
}
