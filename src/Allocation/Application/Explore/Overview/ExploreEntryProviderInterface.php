<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\Overview;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Adds tiles to the Explore overview without a dependency from Allocation on the providing context.
 * Providers run on every request of the overview; visibility is the entry's granted attribute.
 */
#[AutoconfigureTag(self::TAG)]
interface ExploreEntryProviderInterface
{
    public const string TAG = 'allocation.explore_entry';

    /**
     * @return list<ExploreEntry>
     */
    public function exploreEntries(): array;
}
