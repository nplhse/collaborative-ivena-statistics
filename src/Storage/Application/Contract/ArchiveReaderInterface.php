<?php

declare(strict_types=1);

namespace App\Storage\Application\Contract;

use App\Storage\Application\Backup\BackupFailed;

/**
 * Reads a file archive of a backup without extracting it (ADR 038, "Verify").
 */
interface ArchiveReaderInterface
{
    /**
     * The regular files of the archive with their size and SHA-256, by relative path.
     *
     * @return array<string, array{size: int, sha256: string}>
     *
     * @throws BackupFailed when the archive is damaged or holds an entry a restore must not extract (absolute
     *                      path, "..", link, device)
     */
    public function entries(string $archiveFile): array;
}
