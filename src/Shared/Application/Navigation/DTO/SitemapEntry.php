<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\DTO;

use App\Shared\Application\Navigation\Menu\NavBadge;

/**
 * One entry of the HTML sitemap. An entry without URL only groups its children (for example the Insights
 * dimensions).
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the sitemap template.
 */
final readonly class SitemapEntry
{
    /**
     * @param list<SitemapEntry> $children
     */
    public function __construct(
        public string $label,
        public ?string $url = null,
        public ?NavBadge $badge = null,
        public array $children = [],
    ) {
    }

    /**
     * @param list<SitemapEntry> $entries
     */
    public static function countLinks(array $entries): int
    {
        $count = 0;
        foreach ($entries as $entry) {
            $count += (null === $entry->url ? 0 : 1) + self::countLinks($entry->children);
        }

        return $count;
    }
}
