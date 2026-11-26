<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\DTO;

/**
 * One tile of the HTML sitemap: an area of the primary menu, a footer column or the remaining content pages.
 *
 * A section without groups is a plain link to its area (for example Import). `url` is the landing page of
 * the area and null for footer columns and the page tree.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the sitemap template.
 */
final readonly class SitemapSection
{
    /**
     * @param list<SitemapGroup> $groups
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $url = null,
        public ?string $icon = null,
        public array $groups = [],
    ) {
    }

    /**
     * Number of links in the section, nested entries included; the template sizes the tile by it.
     */
    public function linkCount(): int
    {
        $count = 0;
        foreach ($this->groups as $group) {
            $count += SitemapEntry::countLinks($group->entries);
        }

        return $count;
    }
}
