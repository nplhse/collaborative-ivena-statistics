<?php

declare(strict_types=1);

namespace App\Encounter\UI\Navigation;

use App\Allocation\Application\Explore\Overview\ExploreEntry;
use App\Allocation\Application\Explore\Overview\ExploreEntryProviderInterface;
use App\Allocation\Application\Explore\Overview\ExploreGroup;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Contract\Display\ExploreEntry as SchemaExploreEntry;
use App\Encounter\Contract\Display\ExploreEntryProviderInterface as SchemaExploreEntryProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Forwards the Explore tiles of encounter schemas (ADR 037) to the Explore overview of Allocation, in the group of
 * case data and after the ED visit list. They are shown to users who may view cases of at least one hospital; each
 * target page checks the encounter grant per hospital (ADR 028). The core forwards them, so the tiles of a schema
 * stay when the ED visit plugin is not installed (#1300).
 *
 * @psalm-suppress UnusedClass Tagged via ExploreEntryProviderInterface.
 */
final readonly class SchemaExploreEntryForwarder implements ExploreEntryProviderInterface
{
    /**
     * @param iterable<SchemaExploreEntryProviderInterface> $schemaProviders
     */
    public function __construct(
        #[AutowireIterator(SchemaExploreEntryProviderInterface::TAG)]
        private iterable $schemaProviders,
    ) {
    }

    #[\Override]
    public function exploreEntries(): array
    {
        $entries = [];
        foreach ($this->schemaProviders as $provider) {
            foreach ($provider->exploreEntries() as $entry) {
                $entries[] = $this->forExplore($entry);
            }
        }

        return $entries;
    }

    private function forExplore(SchemaExploreEntry $entry): ExploreEntry
    {
        return new ExploreEntry(
            key: $entry->key,
            group: ExploreGroup::Cases,
            title: $entry->title,
            description: $entry->description,
            icon: $entry->icon,
            route: $entry->route,
            grantedAttribute: EncounterAttribute::VIEW_CASES_IN_ANY_HOSPITAL,
            routeParameters: $entry->routeParameters,
            beta: $entry->beta,
            // After the ED visit list.
            weight: 10 + $entry->weight,
        );
    }
}
