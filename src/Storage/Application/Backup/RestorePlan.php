<?php

declare(strict_types=1);

namespace App\Storage\Application\Backup;

/**
 * What a restore will replace, shown before it starts (ADR 038, "Restore").
 *
 * With keepPrevious the current database and directories stay until the restore is complete, so a failure can be
 * undone; this needs space for a second copy. Without it the current schema is dropped before the dump is restored.
 */
final readonly class RestorePlan
{
    /**
     * @param list<RestoreTarget> $targets
     * @param list<string>        $warnings
     */
    public function __construct(
        public string $backupDirectory,
        public BackupManifest $manifest,
        public string $database,
        public array $targets,
        public MigrationState $migrations,
        public array $warnings,
        public bool $keepPrevious = false,
    ) {
    }
}
