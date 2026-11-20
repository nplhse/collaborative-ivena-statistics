<?php

declare(strict_types=1);

namespace App\Storage\Application\Contract;

use App\Storage\Application\Backup\ArchivedFiles;
use App\Storage\Application\Backup\BackupFailed;
use App\Storage\Application\Backup\FileComponentSource;

/**
 * Packs the regular files of one component into a compressed archive plus a SHA-256 list (ADR 038).
 */
interface FileArchiverInterface
{
    /**
     * @throws BackupFailed
     */
    public function preflight(): void;

    /**
     * Writes no archive and no list when the component has no files.
     *
     * @throws BackupFailed when the source cannot be read or a file changes while it is packed
     */
    public function archive(FileComponentSource $source, string $archiveFile, string $checksumFile): ArchivedFiles;
}
