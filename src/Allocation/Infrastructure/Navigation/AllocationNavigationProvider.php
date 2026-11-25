<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Navigation;

use App\Allocation\Application\Explore\Catalog\CatalogGlossaryEntryRegistry;
use App\Shared\Application\Navigation\Menu\Menu;
use App\Shared\Application\Navigation\Menu\NavGroup;
use App\Shared\Application\Navigation\Menu\NavigationProviderInterface;
use App\Shared\Application\Navigation\Menu\NavItem;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Explore in the primary menu, the explore sidebar (ADR 035), and the reference entries of the
 * help column in the footer (ADR 042).
 *
 * Allocation owns the explore menu: its groups follow the former Explore dropdowns (cases, organisation,
 * hospital structure, features) with the overview first. Every entry needs
 * ROLE_PARTICIPANT like the Explore pages; a user who only opens their own profile under /explore/user sees
 * no sidebar. Users are added by the User context to {@see Menu::EXPLORE_GROUP_ORGANISATION}.
 *
 * @psalm-suppress UnusedClass Tagged via NavigationProviderInterface.
 */
final readonly class AllocationNavigationProvider implements NavigationProviderInterface
{
    private const string PARTICIPANT = 'ROLE_PARTICIPANT';

    public function __construct(
        private CatalogGlossaryEntryRegistry $glossaryEntries,
    ) {
    }

    #[\Override]
    public function groups(Menu $menu): array
    {
        if (Menu::Explore !== $menu) {
            return [];
        }

        return [
            new NavGroup('explore-start', null, 10),
            new NavGroup(Menu::EXPLORE_GROUP_CASES, $this->label('link.explore.group.cases'), 20),
            new NavGroup(Menu::EXPLORE_GROUP_ORGANISATION, $this->label('link.explore.group.organisation'), 30),
            new NavGroup('structure', $this->label('link.explore.group.structure'), 40),
            new NavGroup('features', $this->label('link.explore.group.features'), 50),
        ];
    }

    #[\Override]
    public function items(Menu $menu): array
    {
        return match ($menu) {
            Menu::Primary => $this->primaryItems(),
            Menu::Explore => $this->sidebarItems(),
            Menu::Footer => [...$this->referenceItems(), $this->dataSourcesItem()],
            default => [],
        };
    }

    /**
     * @return list<NavItem>
     */
    private function primaryItems(): array
    {
        return [
            new NavItem(
                id: 'explore',
                parentId: Menu::PRIMARY_GROUP,
                label: $this->label('link.explore'),
                route: 'app_explore_index',
                icon: 'tabler:compass',
                grantedAttribute: self::PARTICIPANT,
                weight: 10,
                areaRoutePrefix: 'app_explore',
            ),
        ];
    }

    /**
     * Entries keep the order of the former Explore dropdowns.
     *
     * @return list<NavItem>
     */
    private function sidebarItems(): array
    {
        return [
            $this->entry('explore-index', 'explore-start', 'link.explore.overview', 'app_explore_index', 'tabler:compass', 10),

            $this->catalog('allocations', Menu::EXPLORE_GROUP_CASES, 'link.allocations', 'app_explore_allocation', 'tabler:ambulance', 10),
            $this->catalog('mci-cases', Menu::EXPLORE_GROUP_CASES, 'link.mci_cases', 'app_explore_mci_case', 'tabler:alert-triangle', 20),

            $this->catalog('hospitals', Menu::EXPLORE_GROUP_ORGANISATION, 'link.hospitals', 'app_explore_hospital', 'tabler:building-hospital', 10),
            $this->catalog('states', Menu::EXPLORE_GROUP_ORGANISATION, 'link.states', 'app_explore_state', 'tabler:map', 20),
            $this->catalog('dispatch-areas', Menu::EXPLORE_GROUP_ORGANISATION, 'link.dispatch_areas', 'app_explore_dispatch_area', 'tabler:map-pin', 30),

            $this->catalog('specialities', 'structure', 'link.specialities', 'app_explore_speciality', 'tabler:hierarchy-2', 10),
            $this->catalog('departments', 'structure', 'link.departments', 'app_explore_department', 'tabler:bed', 20),

            $this->catalog('indications', 'features', 'link.indications', 'app_explore_indication', 'tabler:heartbeat', 10),
            $this->catalog('indication-groups', 'features', 'link.indication_groups', 'app_explore_indication_group', 'tabler:stack-2', 20),
            $this->catalog('assignments', 'features', 'link.assignments', 'app_explore_assignment', 'tabler:transfer', 30),
            $this->catalog('infections', 'features', 'link.infections', 'app_explore_infection', 'tabler:virus', 40),
            $this->catalog('occasions', 'features', 'link.occasions', 'app_explore_occasion', 'tabler:flag', 50),
            $this->catalog('secondary-transports', 'features', 'link.secondary_transports', 'app_explore_secondary_transport', 'tabler:helicopter', 60),
        ];
    }

    /**
     * Glossary and data catalog in the help column of the footer, which also feeds the Reference card of the
     * help (ADR 042). Both stay reserved for participants.
     *
     * @return list<NavItem>
     */
    private function referenceItems(): array
    {
        return [
            $this->entry('glossary', Menu::FOOTER_GROUP_HELP, 'link.glossary', 'app_help_glossary_index', 'tabler:book', 20, $this->glossaryRoutes()),
            $this->entry('data-catalog', Menu::FOOTER_GROUP_HELP, 'link.data_catalog', 'app_help_data_catalog_index', 'tabler:database', 30, ['app_help_data_catalog_show']),
        ];
    }

    /**
     * Public, in the legal column after the CMS pages (#1516).
     */
    private function dataSourcesItem(): NavItem
    {
        return new NavItem(
            id: 'data-sources',
            parentId: Menu::FOOTER_GROUP_LEGAL,
            label: $this->label('link.data_sources'),
            route: 'app_data_sources',
            weight: 40,
        );
    }

    /**
     * The catalog glossary pages and the glossary pages other contexts add through the glossary registry
     * (for example the MTS glossary of the Encounter context), with their further pages.
     *
     * @return list<string>
     */
    private function glossaryRoutes(): array
    {
        $routes = ['app_help_glossary_show'];
        foreach ($this->glossaryEntries->entries() as $entry) {
            $routes = [...$routes, $entry->route, ...$entry->detailRoutes];
        }

        return array_values(array_unique($routes));
    }

    /**
     * A catalog list with its detail page: routes `{prefix}_list` and `{prefix}_show`.
     */
    private function catalog(string $id, string $groupId, string $labelKey, string $routePrefix, string $icon, int $weight): NavItem
    {
        return $this->entry($id, $groupId, $labelKey, $routePrefix.'_list', $icon, $weight, [$routePrefix.'_show']);
    }

    /**
     * @param list<string> $detailRoutes
     */
    private function entry(string $id, string $groupId, string $labelKey, string $route, string $icon, int $weight, array $detailRoutes = []): NavItem
    {
        return new NavItem(
            id: $id,
            parentId: $groupId,
            label: $this->label($labelKey),
            route: $route,
            icon: $icon,
            grantedAttribute: self::PARTICIPANT,
            detailRoutes: $detailRoutes,
            weight: $weight,
        );
    }

    private function label(string $key): TranslatableMessage
    {
        return new TranslatableMessage($key, [], 'shared');
    }
}
