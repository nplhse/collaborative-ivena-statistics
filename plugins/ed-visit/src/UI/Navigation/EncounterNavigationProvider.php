<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\UI\Navigation;

use App\Shared\Application\Navigation\Menu\Menu;
use App\Shared\Application\Navigation\Menu\NavBadge;
use App\Shared\Application\Navigation\Menu\NavigationProviderInterface;
use App\Shared\Application\Navigation\Menu\NavItem;
use App\User\Domain\Security\UserRole;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Adds the ED visit list (#1178) to the case group of the explore sidebar and the encounter analytics (#1055) to
 * the clinical group of the statistics sidebar. The entries need only the beta role, because navigation must not
 * query the database; the pages check VIEW_CASES or VIEW_AGGREGATES per hospital (ADR 028).
 *
 * @psalm-suppress UnusedClass Tagged via NavigationProviderInterface.
 */
final readonly class EncounterNavigationProvider implements NavigationProviderInterface
{
    #[\Override]
    public function groups(Menu $menu): array
    {
        return [];
    }

    #[\Override]
    public function items(Menu $menu): array
    {
        if (Menu::Statistics === $menu) {
            return [
                new NavItem(
                    id: 'encounter-analytics',
                    parentId: Menu::STATISTICS_GROUP_CLINICAL,
                    label: new TranslatableMessage('stats.nav.encounter', [], 'statistics'),
                    route: 'app_stats_encounter_analytics',
                    icon: 'tabler:stethoscope',
                    badge: new NavBadge(new TranslatableMessage('stats.nav.badge.beta', [], 'statistics')),
                    grantedAttribute: UserRole::ENCOUNTER_BETA,
                ),
            ];
        }

        if (Menu::Explore !== $menu) {
            return [];
        }

        return [
            new NavItem(
                id: 'ed-visits',
                parentId: Menu::EXPLORE_GROUP_CASES,
                label: new TranslatableMessage('encounter.visit_list.title', [], 'encounter'),
                route: 'app_explore_ed_visit_hospitals',
                icon: 'tabler:stethoscope',
                badge: new NavBadge(new TranslatableMessage('tools.beta', [], 'shared')),
                grantedAttribute: UserRole::ENCOUNTER_BETA,
                detailRoutes: ['app_explore_ed_visit_list', 'app_explore_ed_visit_show'],
                weight: 30,
            ),
        ];
    }
}
