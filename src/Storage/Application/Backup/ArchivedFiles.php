<?php

declare(strict_types=1);

namespace App\Storage\Application\Backup;

/**
 * The result of packing one file component (ADR 038, "Components").
 *
 * Counts and bytes are regular files only. Symbolic links are never followed or archived; they are counted so
 * the operator sees that something was left out. Without any file no archive is written; a source directory
 * that does not exist counts as an empty component and is marked, so a wrong path does not go unnoticed.
 */
final readonly class ArchivedFiles
{
    public function __construct(
        public int $files,
        public int $bytes,
        public int $skippedLinks,
        public bool $sourceMissing = false,
    ) {
    }

    public function hasArchive(): bool
    {
        return $this->files > 0;
    }
}
