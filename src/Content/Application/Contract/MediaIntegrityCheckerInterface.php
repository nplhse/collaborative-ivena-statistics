<?php

declare(strict_types=1);

namespace App\Content\Application\Contract;

use App\Content\Application\Media\MediaIntegrityReport;
use App\Content\Application\Media\MediaMaintenanceException;

/**
 * Compares the media storage with the media items and removes files no media item refers to (ADR 039).
 */
interface MediaIntegrityCheckerInterface
{
    /**
     * @param bool $full   hash every file instead of the files with the oldest verification
     * @param bool $dryRun report what would be deleted, delete and store nothing
     *
     * @throws MediaMaintenanceException when a backup, restore or another media task runs
     */
    public function check(bool $full, bool $dryRun): MediaIntegrityReport;
}
