<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Media;

use App\Content\Domain\ValueObject\ImageDimensions;

final readonly class MediaDimensionsExtractor
{
    public function extract(string $absolutePath): ?ImageDimensions
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return null;
        }

        $size = @getimagesize($absolutePath);
        if (!is_array($size)) {
            return null;
        }

        $width = $size[0];
        $height = $size[1];

        if ($width <= 0 || $height <= 0) {
            return null;
        }

        return new ImageDimensions($width, $height);
    }
}
