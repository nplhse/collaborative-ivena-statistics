<?php

declare(strict_types=1);

namespace App\Content\Application\Contract;

use App\Content\Application\Help\HelpSearchHit;

/** Full-text search in help articles (#596). */
interface HelpSearchQueryInterface
{
    public const int MAX_QUERY_LENGTH = 100;
    public const int MAX_RESULTS = 20;

    /**
     * Searches the published translation each page shows in $locale (falling back to $defaultLocale, as pages do),
     * with German or English word stems by its language and prefix matching on every word. Titles weigh more than
     * text. Terms without a letter or digit find nothing.
     *
     * @param list<int> $pageIds the help articles the viewer may see
     *
     * @return list<HelpSearchHit> best matches first, at most {@see MAX_RESULTS}
     */
    public function search(string $terms, array $pageIds, string $locale, string $defaultLocale): array;
}
