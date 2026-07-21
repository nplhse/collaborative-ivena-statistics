<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Contract;

use App\Shared\Application\Navigation\DTO\SitemapPageNode;

interface NavigationPageLinksProviderInterface
{
    /**
     * @return list<array{url: string, label: string}>
     */
    public function linksForKeys(string ...$keys): array;

    /**
     * @return list<SitemapPageNode>
     */
    public function visiblePublishedPageTree(): array;
}
