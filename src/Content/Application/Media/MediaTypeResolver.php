<?php

declare(strict_types=1);

namespace App\Content\Application\Media;

use App\Content\Domain\Entity\Media;
use App\Content\Domain\Enum\MediaType;

final readonly class MediaTypeResolver
{
    public function resolveFromMime(?string $mimeType): MediaType
    {
        $mime = strtolower((string) $mimeType);

        if (str_starts_with($mime, 'image/')) {
            return MediaType::IMAGE;
        }

        if ('application/pdf' === $mime) {
            return MediaType::PDF;
        }

        return MediaType::DOCUMENT;
    }

    public function applyTo(Media $media): void
    {
        $media->setType($this->resolveFromMime($media->getMimeType()));
    }
}
