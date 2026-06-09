<?php

declare(strict_types=1);

namespace App\Content\Application\Page\DTO;

final readonly class PageImageContentFinding
{
    public function __construct(
        public int $pageId,
        public string $pageTitle,
        public string $pageSlug,
        public int $blockIndex,
        public string $blockType,
        public ?int $mediaId,
        public ?string $filename,
        public ?int $imageWidth,
        public ?int $imageHeight,
        public string $currentSize,
        public string $float,
        public string $status,
        public string $recommendation,
    ) {
    }
}
