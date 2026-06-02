<?php

declare(strict_types=1);

namespace App\Content\Application\Media;

use App\Content\Domain\Entity\Media;

final readonly class MediaPublicUrlResolver
{
    private const string URI_PREFIX = '/uploads/media';

    public function resolve(Media $media): string
    {
        $filename = $media->getFilename();
        if (null === $filename || '' === $filename) {
            return '';
        }

        return self::URI_PREFIX.'/'.$filename;
    }
}
