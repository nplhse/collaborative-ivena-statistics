<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\DTO;

final readonly class SitemapSection
{
    /**
     * @param list<SitemapLink>     $links
     * @param list<SitemapPageNode> $pageTree
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public array $links,
        public array $pageTree = [],
    ) {
    }
}
