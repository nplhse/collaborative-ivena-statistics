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
        /** A help article (#596): left out of the general page tree, shown in the help navigation. */
        public bool $help = false,
        /** Short description of the shown translation (#1578), null when the editor wrote none. */
        public ?string $summary = null,
        /** Estimated reading time of the shown translation in minutes (#1578). */
        public int $readingMinutes = 1,
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
            help: $this->help,
            summary: $this->summary,
            readingMinutes: $this->readingMinutes,
        );
    }
}
