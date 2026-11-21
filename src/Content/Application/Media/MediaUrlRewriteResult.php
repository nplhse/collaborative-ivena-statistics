<?php

declare(strict_types=1);

namespace App\Content\Application\Media;

/**
 * Outcome of rewriting old media URLs in content (ADR 039, "Rewriting old URLs in content").
 */
final readonly class MediaUrlRewriteResult
{
    /**
     * @param array<string, int> $changedRecords records with at least one rewritten URL, by what they are
     *                                           (`post`, `page`, `page draft`)
     * @param list<string>       $unresolved     old file names found in content that belong to no moved media item
     */
    public function __construct(
        public int $rewrittenUrls,
        public array $changedRecords,
        public array $unresolved,
    ) {
    }
}
