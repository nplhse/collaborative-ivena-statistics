<?php

declare(strict_types=1);

namespace App\Tests\Support\Encounter\Probe;

use App\Encounter\Contract\Display\ExploreEntry;
use App\Encounter\Contract\Display\ExploreEntryProviderInterface;

/**
 * Explore tile of the test-only schema `probe`. It has no page of its own and points at the Explore
 * overview; the target page of a real schema checks its access per hospital.
 */
final readonly class ProbeExploreEntryProvider implements ExploreEntryProviderInterface
{
    #[\Override]
    public function exploreEntries(): array
    {
        return [new ExploreEntry(
            key: 'probe-records',
            title: new ProbeLabel(),
            description: new ProbeLabel(),
            icon: 'tabler:bed',
            route: 'app_explore_index',
        )];
    }
}
