<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Display;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Tiles of a schema on the Explore overview (ADR 037); Encounter forwards them to Allocation's extension
 * point. Runs on every request of the overview, so do not query the database.
 */
#[AutoconfigureTag(self::TAG)]
interface ExploreEntryProviderInterface
{
    public const string TAG = 'encounter.explore_entry';

    /**
     * @return list<ExploreEntry>
     */
    public function exploreEntries(): array;
}
