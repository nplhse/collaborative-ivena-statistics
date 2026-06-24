<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Media;

use App\Content\Domain\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class LocalMediaFileLocator
{
    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        #[Autowire('%app.media_upload_dir%')]
        private string $uploadDir,
    ) {
    }

    public function resolveAbsolutePath(Media $media): ?string
    {
        $filename = $media->getFilename();
        if (null === $filename || '' === $filename) {
            return null;
        }

        return rtrim($this->uploadDir, '/').'/'.$filename;
    }

    public function resolveAbsolutePathFromFilename(string $filename): string
    {
        return rtrim($this->uploadDir, '/').'/'.ltrim($filename, '/');
    }

    public function resolveFilenameFromPublicSrc(string $src): ?string
    {
        $prefix = '/uploads/media/';
        if (!str_starts_with($src, $prefix)) {
            return null;
        }

        $filename = substr($src, strlen($prefix));

        return '' !== $filename ? $filename : null;
    }
}
