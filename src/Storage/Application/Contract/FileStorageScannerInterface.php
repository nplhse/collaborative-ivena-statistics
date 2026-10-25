<?php

declare(strict_types=1);

namespace App\Storage\Application\Contract;

use App\Storage\Application\Layout\StorageLayout;
use App\Storage\Application\Scan\FileScan;

/**
 * Walks the measurement root once and attributes every inode to one location and category
 * (ADR 017, "Usage metric").
 */
interface FileStorageScannerInterface
{
    public function scan(StorageLayout $layout): FileScan;
}
