<?php

declare(strict_types=1);

namespace App\Storage\Application\Backup;

/**
 * Where one file component of a backup is restored to (ADR 038, "Restore").
 *
 * The path is the real directory: a Deployer `shared/` link is resolved, so the restore replaces the shared
 * directory and not the link inside the release.
 */
final readonly class RestoreTarget
{
    public function __construct(
        public BackupComponent $component,
        public string $path,
        public ArchivedFiles $archived,
        public bool $exists,
    ) {
    }
}
