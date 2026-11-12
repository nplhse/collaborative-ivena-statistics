<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\Catalog;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Extension point for entries on the Explore glossary hub (`/explore/glossary`).
 *
 * Other contexts add their own classification pages (for example the MTS glossary of the encounter
 * context) on their own routes without a dependency from Allocation on them. Route names should start
 * with `app_explore_glossary_` so the Explore subnav marks the glossary as active. Providers check
 * access themselves and return no entry when the current user may not open the page.
 */
#[AutoconfigureTag(self::TAG)]
interface CatalogGlossaryEntryProviderInterface
{
    public const string TAG = 'allocation.catalog_glossary_entry';

    /**
     * @return list<CatalogGlossaryEntry>
     */
    public function glossaryEntries(): array;
}
