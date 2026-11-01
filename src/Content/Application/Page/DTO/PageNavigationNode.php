<?php

declare(strict_types=1);

namespace App\Content\Application\Page\DTO;

use App\Content\Domain\Enum\PageKey;

/**
 * One visible page in the page navigation, with its visible children in navigation order.
 *
 * The state flags are set by {@see \App\Content\Application\Page\PageNavigationTree} for one
 * navigation instance; the provider returns nodes without state.
 */
final readonly class PageNavigationNode
{
    /**
     * @param list<self> $children
     */
    public function __construct(
        public int $pageId,
        public ?PageKey $key,
        public string $title,
        public string $url,
        public array $children = [],
        public bool $current = false,
        public bool $ancestor = false,
        public bool $expanded = false,
    ) {
    }

    public function hasChildren(): bool
    {
        return [] !== $this->children;
    }

    /**
     * @param list<self> $children
     */
    public function withState(array $children, bool $current, bool $ancestor, bool $expanded): self
    {
        return new self(
            pageId: $this->pageId,
            key: $this->key,
            title: $this->title,
            url: $this->url,
            children: $children,
            current: $current,
            ancestor: $ancestor,
            expanded: $expanded,
        );
    }
}
