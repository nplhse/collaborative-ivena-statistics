<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\Overview;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Contributed tiles of the Explore overview by group, ordered by weight, then key. Keys are unique.
 */
final readonly class ExploreEntryRegistry
{
    /**
     * @param iterable<ExploreEntryProviderInterface> $providers
     *
     * @psalm-suppress PossiblyUnusedMethod Autowired.
     */
    public function __construct(
        #[AutowireIterator(ExploreEntryProviderInterface::TAG)]
        private iterable $providers,
    ) {
    }

    /**
     * @return array<string, list<ExploreEntry>> by ExploreGroup value
     */
    public function byGroup(): array
    {
        $entries = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->exploreEntries() as $entry) {
                if (isset($entries[$entry->key])) {
                    throw new \LogicException(sprintf('Explore entry "%s" is contributed twice.', $entry->key));
                }

                $entries[$entry->key] = $entry;
            }
        }

        uasort($entries, static fn (ExploreEntry $a, ExploreEntry $b): int => [$a->weight, $a->key] <=> [$b->weight, $b->key]);

        $byGroup = [];
        foreach ($entries as $entry) {
            $byGroup[$entry->group->value][] = $entry;
        }

        return $byGroup;
    }
}
