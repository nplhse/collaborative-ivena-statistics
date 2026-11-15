<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Menu;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds a menu of the navigation tree for the current user and route (ADR 035).
 *
 * - Merges the groups and items of all providers and rejects duplicate ids, unknown parents and cycles.
 * - Hides items whose `is_granted` attribute fails, together with their children; drops items without
 *   route that have no visible child, and groups without visible items.
 * - Sorts every level by weight, then by the translated label in the current locale, then by id.
 * - Maps the current route name exactly to one entry and marks the path from its group down to it. In
 *   the primary menu, when no own entry matches: a route of a sidebar menu marks the primary entry of that
 *   menu; then `$area` (the sidebar menu of the page); then the primary entry with the longest
 *   `areaRoutePrefix` the route starts with. The menu says which of these rules chose the active entry.
 *
 * Merging and visibility live in {@see NavigationContributions}, the order in {@see NavigationOrder}; the
 * admin navigation inspector (#1237) uses the same classes.
 */
final readonly class NavigationBuilder
{
    private NavigationOrder $order;

    /**
     * @param iterable<NavigationProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(NavigationProviderInterface::TAG)]
        private iterable $providers,
        private AuthorizationCheckerInterface $authorizationChecker,
        TranslatorInterface $translator,
    ) {
        $this->order = new NavigationOrder($translator);
    }

    /**
     * @param array<string, mixed>          $currentRouteParameters
     * @param (\Closure(string): bool)|null $isGranted              permission check instead of the current user's,
     *                                                              for the admin navigation inspector
     */
    public function build(Menu $menu, ?string $currentRoute = null, ?Menu $area = null, array $currentRouteParameters = [], ?\Closure $isGranted = null): NavigationMenu
    {
        $isGranted ??= fn (string $attribute): bool => $this->authorizationChecker->isGranted($attribute);
        $contributions = $this->contributions($menu);
        $visibleChildren = $this->visibleChildren($contributions, $isGranted);

        $match = null === $currentRoute ? null : $this->matchRoute($visibleChildren, $currentRoute, $currentRouteParameters);
        if (null === $match && Menu::Primary === $menu) {
            $match = $this->primaryEntryFor($currentRoute, $currentRouteParameters, $area, $visibleChildren, $isGranted);
        }

        [$activeId, $activeRule] = $match ?? [null, null];
        $activePath = null === $activeId ? [] : $this->pathTo($activeId, $visibleChildren);
        $onPath = array_flip($activePath);

        $sections = [];
        foreach ($this->order->sort(array_values($contributions->groups)) as $group) {
            $nodes = $this->nodes($group->id, $visibleChildren, $activeId, $onPath);
            if ([] === $nodes) {
                continue;
            }

            $sections[] = new NavigationSection($group, $nodes, isset($onPath[$group->id]));
        }

        return new NavigationMenu($menu, $sections, $activePath, $activeRule);
    }

    /**
     * Route names that more than one entry of the menu claims with the same route parameters, regardless of
     * permissions.
     *
     * @return array<string, list<string>> route name, with the parameters if any => ids of the entries that claim it
     */
    public function duplicateRouteClaims(Menu $menu): array
    {
        return $this->contributions($menu)->duplicateRouteClaims();
    }

    /**
     * Pairs of area route prefixes in the menu where one starts with the other, so a route could fall into
     * two areas, regardless of permissions.
     *
     * @return list<string> human-readable descriptions of the overlaps
     */
    public function overlappingAreaRoutePrefixes(Menu $menu): array
    {
        return $this->contributions($menu)->overlappingAreaRoutePrefixes();
    }

    private function contributions(Menu $menu): NavigationContributions
    {
        $contributions = NavigationContributions::collect($this->providers, $menu);
        if ([] !== $contributions->problems) {
            throw new \LogicException($contributions->problems[0]);
        }

        return $contributions;
    }

    /**
     * Visible items by the id of their parent (group or item), in provider order.
     *
     * @param \Closure(string): bool $isGranted
     *
     * @return array<string, list<NavItem>>
     */
    private function visibleChildren(NavigationContributions $contributions, \Closure $isGranted): array
    {
        $visibility = $contributions->visibility($isGranted);

        $visible = [];
        foreach ($contributions->items as $item) {
            if (NavigationVisibility::Visible === $visibility[$item->id]) {
                $visible[$item->parentId][] = $item;
            }
        }

        return $visible;
    }

    /**
     * @param array<string, list<NavItem>> $visibleChildren
     * @param array<string, mixed>         $routeParameters
     *
     * @return array{string, NavigationActiveRule}|null
     */
    private function matchRoute(array $visibleChildren, string $route, array $routeParameters): ?array
    {
        foreach ($visibleChildren as $children) {
            foreach ($children as $item) {
                if ($item->matches($route, $routeParameters)) {
                    return [$item->id, $route === $item->route ? NavigationActiveRule::Route : NavigationActiveRule::DetailRoute];
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>         $routeParameters
     * @param array<string, list<NavItem>> $visibleChildren of the primary menu
     * @param \Closure(string): bool       $isGranted
     *
     * @return array{string, NavigationActiveRule}|null
     */
    private function primaryEntryFor(?string $route, array $routeParameters, ?Menu $area, array $visibleChildren, \Closure $isGranted): ?array
    {
        $candidates = [];
        if (null !== $route) {
            foreach (Menu::cases() as $sidebar) {
                $entryId = $sidebar->primaryEntryId();
                if (null === $entryId) {
                    continue;
                }

                if (null !== $this->matchRoute($this->visibleChildren($this->contributions($sidebar), $isGranted), $route, $routeParameters)) {
                    $candidates[] = [$entryId, NavigationActiveRule::SidebarMenu];
                }
            }
        }

        $areaEntryId = $area?->primaryEntryId();
        if (null !== $areaEntryId) {
            $candidates[] = [$areaEntryId, NavigationActiveRule::Area];
        }

        $visibleIds = [];
        $byPrefix = [];
        foreach ($visibleChildren as $children) {
            foreach ($children as $item) {
                $visibleIds[$item->id] = true;
                if (null !== $item->areaRoutePrefix && null !== $route && str_starts_with($route, $item->areaRoutePrefix)) {
                    $byPrefix[$item->areaRoutePrefix] = $item->id;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (isset($visibleIds[$candidate[0]])) {
                return $candidate;
            }
        }

        if ([] === $byPrefix) {
            return null;
        }

        uksort($byPrefix, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return [reset($byPrefix), NavigationActiveRule::AreaRoutePrefix];
    }

    /**
     * @param array<string, list<NavItem>> $visibleChildren
     *
     * @return list<string> ids from the group down to the entry
     */
    private function pathTo(string $id, array $visibleChildren): array
    {
        $parentOf = [];
        foreach ($visibleChildren as $parentId => $children) {
            foreach ($children as $item) {
                $parentOf[$item->id] = $parentId;
            }
        }

        $path = [$id];
        while (isset($parentOf[$id])) {
            $id = $parentOf[$id];
            $path[] = $id;
        }

        return array_reverse($path);
    }

    /**
     * @param array<string, list<NavItem>> $visibleChildren
     * @param array<string, int>           $onPath
     *
     * @return list<NavigationNode>
     */
    private function nodes(string $parentId, array $visibleChildren, ?string $activeId, array $onPath): array
    {
        $nodes = [];
        foreach ($this->order->sort($visibleChildren[$parentId] ?? []) as $item) {
            $nodes[] = new NavigationNode(
                $item,
                $this->nodes($item->id, $visibleChildren, $activeId, $onPath),
                $item->id === $activeId,
                isset($onPath[$item->id]),
            );
        }

        return $nodes;
    }
}
