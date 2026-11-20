<?php

declare(strict_types=1);

namespace App\Storage\Application\Contract;

use App\Storage\Application\Backup\BackupFailed;

/**
 * Extracts a verified file archive of a backup (ADR 038, "Restore").
 */
interface ArchiveExtractorInterface
{
    /**
     * @throws BackupFailed
     */
    public function extract(string $archiveFile, string $targetDirectory): void;
}
