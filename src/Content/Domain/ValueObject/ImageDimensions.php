<?php

declare(strict_types=1);

namespace App\Content\Domain\ValueObject;

final readonly class ImageDimensions
{
    public function __construct(
        public int $width,
        public int $height,
    ) {
    }
}
