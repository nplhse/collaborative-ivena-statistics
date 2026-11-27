<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Media;

use App\Content\Domain\Entity\Media;

/**
 * Where the file of a media item is on disk: in the media storage (ADR 039).
 */
final readonly class LocalMediaFileLocator
{
    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private MediaStorage $storage,
    ) {
    }

    public function resolveAbsolutePath(Media $media): ?string
    {
        $storageKey = $media->getStorageKey();

        return null !== $storageKey ? $this->resolveAbsolutePathFromStorageKey($storageKey) : null;
    }

    public function resolveAbsolutePathFromStorageKey(string $storageKey): ?string
    {
        return MediaStorage::isValidKey($storageKey) ? $this->storage->absolutePath($storageKey) : null;
    }
}
