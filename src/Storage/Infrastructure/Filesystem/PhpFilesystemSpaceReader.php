<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure\Filesystem;

use App\Storage\Application\Capacity\FilesystemSpace;
use App\Storage\Application\Contract\FilesystemSpaceReaderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** @psalm-suppress UnusedClass Wired via #[AsAlias] for FilesystemSpaceReaderInterface. */
#[AsAlias(FilesystemSpaceReaderInterface::class)]
final readonly class PhpFilesystemSpaceReader implements FilesystemSpaceReaderInterface
{
    #[\Override]
    public function read(string $path): ?FilesystemSpace
    {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        // Implausible values (e.g. from a network filesystem) count as unknown.
        if (false === $total || false === $free || $total <= 0 || $free < 0 || $free > $total) {
            return null;
        }

        return new FilesystemSpace((int) $total, (int) $free);
    }
}
