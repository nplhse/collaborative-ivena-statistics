<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

/**
 * @phpstan-type TocItem array{id: string, text: string, level: int}
 * @phpstan-type ContentBlock array{type: string, data: array<string, mixed>, enabled?: bool}
 */
final readonly class PageTableOfContentsResult
{
    /**
     * @param list<ContentBlock> $content
     * @param list<TocItem>      $items
     */
    public function __construct(
        public array $content,
        public array $items,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->items;
    }
}
