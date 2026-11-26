<?php

declare(strict_types=1);

namespace App\Content\Application\Contract;

use App\Content\Application\Media\MediaMaintenanceException;
use App\Content\Application\Media\MediaStorageMigrationResult;

/**
 * Moves media items from the old upload directory into the media storage (ADR 039).
 */
interface MediaStorageMigratorInterface
{
    /**
     * @param callable(string $filename, string $outcome): void $progress called once per media item
     *
     * @throws MediaMaintenanceException when a backup, restore or another media task runs
     */
    public function migrate(string $legacyDir, bool $dryRun, callable $progress): MediaStorageMigrationResult;
}
