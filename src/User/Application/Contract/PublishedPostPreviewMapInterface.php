<?php

declare(strict_types=1);

namespace App\User\Application\Contract;

interface PublishedPostPreviewMapInterface
{
    /**
     * Sanitized preview HTML keyed by published post slug.
     * Unpublished, scheduled, missing, or empty posts are omitted.
     *
     * @param list<string|null> $slugs
     *
     * @return array<string, string>
     */
    public function forSlugs(array $slugs): array;
}
