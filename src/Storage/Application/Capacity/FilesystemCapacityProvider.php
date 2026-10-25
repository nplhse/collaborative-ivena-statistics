<?php

declare(strict_types=1);

namespace App\Storage\Application\Capacity;

use App\Storage\Application\Contract\CapacityProviderInterface;
use App\Storage\Domain\Enum\CapacitySource;

/**
 * The filesystem size, compared with the filesystem-wide usage, because everything on the disk competes
 * for the same space. Only meaningful on a dedicated server or VPS.
 *
 * @psalm-suppress UnusedClass Tagged via CapacityProviderInterface.
 */
final readonly class FilesystemCapacityProvider implements CapacityProviderInterface
{
    #[\Override]
    public function source(): CapacitySource
    {
        return CapacitySource::Filesystem;
    }

    #[\Override]
    public function provide(?int $measuredUsedBytes, ?FilesystemSpace $filesystem): ?CapacityReference
    {
        if (!$filesystem instanceof FilesystemSpace) {
            return null;
        }

        return new CapacityReference(CapacitySource::Filesystem, $filesystem->totalBytes, $filesystem->usedBytes());
    }
}
