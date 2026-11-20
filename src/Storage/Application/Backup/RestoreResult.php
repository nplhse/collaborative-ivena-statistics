<?php

declare(strict_types=1);

namespace App\Storage\Application\Backup;

/**
 * The outcome of a successful restore (ADR 038, "Restore").
 */
final readonly class RestoreResult
{
    /**
     * @param list<string> $previousDirectories the replaced file directories, kept next to the restored ones
     * @param list<string> $warnings            things that need a look but did not fail the restore
     */
    public function __construct(
        public array $previousDirectories,
        public int $pendingMigrations,
        public array $warnings,
    ) {
    }
}
