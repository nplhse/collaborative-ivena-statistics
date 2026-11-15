<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\DTO;

/**
 * A group inside a sitemap section, for example "Comparisons" in Statistics. A group without label follows
 * the previous one without heading.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the sitemap template.
 */
final readonly class SitemapGroup
{
    /**
     * @param non-empty-list<SitemapEntry> $entries
     */
    public function __construct(
        public ?string $label,
        public array $entries,
    ) {
    }
}
