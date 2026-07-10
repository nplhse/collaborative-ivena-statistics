<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\DTO;

final readonly class SitemapPageNode
{
    /**
     * @param list<SitemapPageNode> $children
     */
    public function __construct(
        public string $url,
        public string $label,
        public array $children = [],
    ) {
    }
}
