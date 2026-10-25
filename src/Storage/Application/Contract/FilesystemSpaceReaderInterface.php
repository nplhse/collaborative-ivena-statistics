<?php

declare(strict_types=1);

namespace App\Storage\Application\Contract;

use App\Storage\Application\Capacity\FilesystemSpace;

interface FilesystemSpaceReaderInterface
{
    /**
     * Null when the filesystem does not report plausible values.
     */
    public function read(string $path): ?FilesystemSpace;
}
