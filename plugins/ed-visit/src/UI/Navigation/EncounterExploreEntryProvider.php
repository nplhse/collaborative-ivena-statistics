<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\UI\Navigation;

use App\Allocation\Application\Explore\Overview\ExploreEntry;
use App\Allocation\Application\Explore\Overview\ExploreEntryProviderInterface;
use App\Allocation\Application\Explore\Overview\ExploreGroup;
use App\User\Domain\Security\UserRole;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The ED visit list (#1178) on the Explore overview, in the group of case data, for the beta role as its sidebar
 * entry. The tiles of other encounter schemas are forwarded by the core (SchemaExploreEntryForwarder, #1300).
 *
 * @psalm-suppress UnusedClass Tagged via ExploreEntryProviderInterface.
 */
final readonly class EncounterExploreEntryProvider implements ExploreEntryProviderInterface
{
    #[\Override]
    public function exploreEntries(): array
    {
        return [new ExploreEntry(
            key: 'ed-visits',
            group: ExploreGroup::Cases,
            title: new TranslatableMessage('encounter.visit_list.title', domain: 'encounter'),
            description: new TranslatableMessage('encounter.explore.tile.ed_visits', domain: 'encounter'),
            icon: 'tabler:stethoscope',
            route: 'app_explore_ed_visit_hospitals',
            grantedAttribute: UserRole::ENCOUNTER_BETA,
            beta: true,
        )];
    }
}
