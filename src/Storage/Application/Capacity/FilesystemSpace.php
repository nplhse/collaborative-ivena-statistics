<?php

declare(strict_types=1);

namespace App\Storage\Application\Capacity;

/**
 * Total and free space of the filesystem the measurement root lives on.
 */
final readonly class FilesystemSpace
{
    public function __construct(
        public int $totalBytes,
        public int $freeBytes,
    ) {
        if ($totalBytes <= 0 || $freeBytes < 0 || $freeBytes > $totalBytes) {
            throw new \InvalidArgumentException(sprintf('Implausible filesystem space: %d free of %d.', $freeBytes, $totalBytes));
        }
    }

    public function usedBytes(): int
    {
        return $this->totalBytes - $this->freeBytes;
    }
}
