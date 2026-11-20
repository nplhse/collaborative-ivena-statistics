<?php

declare(strict_types=1);

namespace App\Storage\Application\Contract;

use App\Storage\Application\Backup\BackupFailed;
use App\Storage\Application\Backup\DatabaseDump;

/**
 * Writes a dump of the application schema (ADR 038).
 *
 * The returned counts and the migration state must come from the same snapshot as the dump.
 */
interface DatabaseDumperInterface
{
    /**
     * The target database for display, without credentials, e.g. "app on 127.0.0.1:5432".
     */
    public function target(): string;

    /**
     * Returns the version of the dump tool; fails when it cannot be run.
     *
     * @throws BackupFailed
     */
    public function preflight(): string;

    /**
     * @throws BackupFailed
     */
    public function dump(string $targetFile): DatabaseDump;
}
