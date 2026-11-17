<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\UI\Navigation;

use App\Allocation\Application\Explore\Overview\ExploreEntry;
use App\Allocation\Application\Explore\Overview\ExploreEntryProviderInterface;
use App\Allocation\Application\Explore\Overview\ExploreGroup;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Contract\Display\ExploreEntry as SchemaExploreEntry;
use App\Encounter\Contract\Display\ExploreEntryProviderInterface as SchemaExploreEntryProviderInterface;
use App\User\Domain\Security\UserRole;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Tiles of the Encounter context on the Explore overview, in the group of case data: the ED visit list
 * (#1178; for the beta role, as its sidebar entry) and the tiles of encounter schemas (ADR 037), which are
 * shown to users who may view cases of at least one hospital. Each target page checks the encounter grant
 * per hospital (ADR 028). No database query here; the visibility check of schema tiles is one query.
 *
 * @psalm-suppress UnusedClass Tagged via ExploreEntryProviderInterface.
 */
final readonly class EncounterExploreEntryProvider implements ExploreEntryProviderInterface
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
        $entries = [new ExploreEntry(
            key: 'ed-visits',
            group: ExploreGroup::Cases,
            title: new TranslatableMessage('encounter.visit_list.title', domain: 'encounter'),
            description: new TranslatableMessage('encounter.explore.tile.ed_visits', domain: 'encounter'),
            icon: 'tabler:stethoscope',
            route: 'app_explore_ed_visit_hospitals',
            grantedAttribute: UserRole::ENCOUNTER_BETA,
            beta: true,
        )];

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
