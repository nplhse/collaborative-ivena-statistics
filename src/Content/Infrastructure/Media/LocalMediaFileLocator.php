<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Media;

use App\Content\Domain\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;

/**
 * Where the file of a media item is on disk: in the media storage (ADR 039), or in the old upload directory for a
 * media item not yet moved (until v0.4).
 */
final readonly class LocalMediaFileLocator
{
    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private MediaStorage $storage,
        #[Autowire(param: 'app.media_legacy_dir')]
        private string $legacyDir,
    ) {
    }

    public function resolveAbsolutePath(Media $media): ?string
    {
        $storageKey = $media->getStorageKey();
        if (null !== $storageKey) {
            return MediaStorage::isValidKey($storageKey) ? $this->storage->absolutePath($storageKey) : null;
        }

        $filename = $media->getFilename();
        if (null === $filename || '' === $filename) {
            return null;
        }

        return $this->resolveLegacyPath($filename);
    }

    public function resolveAbsolutePathFromStorageKey(string $storageKey): ?string
    {
        return MediaStorage::isValidKey($storageKey) ? $this->storage->absolutePath($storageKey) : null;
    }

    /** Path of a file in the old upload directory; null for a name that would leave the directory. */
    public function resolveLegacyPath(string $filename): ?string
    {
        if ('' === $filename || str_contains($filename, '/') || str_contains($filename, '\\') || \in_array($filename, ['.', '..'], true)) {
            return null;
        }

        return Path::join($this->legacyDir, $filename);
    }
}
