<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Media;

use App\Content\Application\Media\MediaTypeResolver;
use App\Content\Domain\Entity\Media;
use App\Content\Domain\Enum\MediaType;
use App\Content\Domain\ValueObject\ImageDimensions;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stores the file a form or a fixture attached to a media item (ADR 039) and records what is known about it: storage
 * key, size, SHA-256, MIME type detected from the content, type and image dimensions.
 *
 * A replaced file keeps its old key until the media item is flushed; {@see \App\Content\Infrastructure\Doctrine\MediaFileRemovalListener}
 * then removes the old file.
 */
final readonly class MediaUploadHandler
{
    /** @var array<string, string> */
    private const array EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private MediaStorage $storage,
        private MediaTypeResolver $mediaTypeResolver,
        private MediaDimensionsExtractor $dimensionsExtractor,
        private ClockInterface $clock,
    ) {
    }

    /** Stores the attached file, if there is one; afterwards the media item has no attached file any more. */
    public function storeAttachedFile(Media $media): void
    {
        $file = $media->getFile();
        if (!$file instanceof File) {
            return;
        }

        $mimeType = $file->getMimeType() ?? 'application/octet-stream';
        $extension = self::EXTENSIONS[$mimeType] ?? $file->guessExtension() ?? 'bin';
        $stored = $this->storage->store($file->getPathname(), $extension);

        $media->setMimeType($mimeType);
        $media->setOriginalFilename($file instanceof UploadedFile ? $file->getClientOriginalName() : $file->getFilename());
        $media->attachStoredFile($stored->storageKey, $stored->size, $stored->checksumSha256, $this->clock->now());
        $this->mediaTypeResolver->applyTo($media);
        $this->applyDimensions($media, $this->storage->absolutePath($stored->storageKey));
        $media->setFile(null);
    }

    private function applyDimensions(Media $media, string $path): void
    {
        $dimensions = MediaType::IMAGE === $media->getType() ? $this->dimensionsExtractor->extract($path) : null;

        $media->setWidth($dimensions instanceof ImageDimensions ? $dimensions->width : null);
        $media->setHeight($dimensions instanceof ImageDimensions ? $dimensions->height : null);
    }
}
