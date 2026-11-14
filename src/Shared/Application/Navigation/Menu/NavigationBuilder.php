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
 *   `areaRoutePrefix` the route starts with.
 */
final readonly class NavigationBuilder
{
    /**
     * @param iterable<NavigationProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(NavigationProviderInterface::TAG)]
        private iterable $providers,
        private AuthorizationCheckerInterface $authorizationChecker,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, mixed> $currentRouteParameters
     */
    public function build(Menu $menu, ?string $currentRoute = null, ?Menu $area = null, array $currentRouteParameters = []): NavigationMenu
    {
        [$groups, $items] = $this->collect($menu);
        $visibleChildren = $this->visibleChildren($groups, $items);

        $activeId = null === $currentRoute ? null : $this->matchRoute($visibleChildren, $currentRoute, $currentRouteParameters);
        if (null === $activeId && Menu::Primary === $menu) {
            $activeId = $this->primaryEntryFor($currentRoute, $currentRouteParameters, $area, $visibleChildren);
        }

        $activePath = null === $activeId ? [] : $this->pathTo($activeId, $visibleChildren);
        $onPath = array_flip($activePath);
        $collator = new \Collator($this->translator->getLocale());

        $sections = [];
        foreach ($this->sorted(array_values($groups), $collator) as $group) {
            $nodes = $this->nodes($group->id, $visibleChildren, $activeId, $onPath, $collator);
            if ([] === $nodes) {
                continue;
            }

            $sections[] = new NavigationSection($group, $nodes, isset($onPath[$group->id]));
        }

        return new NavigationMenu($menu, $sections, $activePath);
    }

    /**
     * Route names that more than one entry of the menu claims with the same route parameters, regardless of
     * permissions.
     *
     * @return array<string, list<string>> route name, with the parameters if any => ids of the entries that claim it
     */
    public function duplicateRouteClaims(Menu $menu): array
    {
        [, $items] = $this->collect($menu);

        $claims = [];
        foreach ($items as $item) {
            $parameters = $item->routeParameters;
            ksort($parameters);
            $suffix = [] === $parameters ? '' : ' '.json_encode($parameters, \JSON_THROW_ON_ERROR);
            foreach (array_unique($item->claimedRoutes()) as $route) {
                $claims[$route.$suffix][] = $item->id;
            }
        }

        return array_filter($claims, static fn (array $ids): bool => \count($ids) > 1);
    }

    /**
     * Pairs of area route prefixes in the menu where one starts with the other, so a route could fall into
     * two areas, regardless of permissions.
     *
     * @return list<string> human-readable descriptions of the overlaps
     */
    public function overlappingAreaRoutePrefixes(Menu $menu): array
    {
        [, $items] = $this->collect($menu);

        $prefixes = [];
        foreach ($items as $item) {
            if (null !== $item->areaRoutePrefix) {
                $prefixes[$item->id] = $item->areaRoutePrefix;
            }
        }

        $overlaps = [];
        foreach ($prefixes as $id => $prefix) {
            foreach ($prefixes as $otherId => $other) {
                if ($id !== $otherId && str_starts_with($other, $prefix)) {
                    $overlaps[] = \sprintf('"%s" (%s) contains "%s" (%s)', $prefix, $id, $other, $otherId);
                }
            }
        }

        return $overlaps;
    }

    /**
     * @return array{array<string, NavGroup>, array<string, NavItem>}
     */
    private function collect(Menu $menu): array
    {
        $groups = [];
        $items = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->groups($menu) as $group) {
                $this->assertNewId($group->id, $groups, $items, $menu);
                $groups[$group->id] = $group;
            }

            foreach ($provider->items($menu) as $item) {
                $this->assertNewId($item->id, $groups, $items, $menu);
                $items[$item->id] = $item;
            }
        }

        foreach ($items as $item) {
            $this->assertReachesGroup($item, $groups, $items, $menu);
        }

        return [$groups, $items];
    }

    /**
     * @param array<string, NavGroup> $groups
     * @param array<string, NavItem>  $items
     */
    private function assertNewId(string $id, array $groups, array $items, Menu $menu): void
    {
        if (isset($groups[$id]) || isset($items[$id])) {
            throw new \LogicException(\sprintf('Navigation id "%s" is registered more than once in menu "%s".', $id, $menu->value));
        }
    }

    /**
     * @param array<string, NavGroup> $groups
     * @param array<string, NavItem>  $items
     */
    private function assertReachesGroup(NavItem $item, array $groups, array $items, Menu $menu): void
    {
        $seen = [$item->id => true];
        $parentId = $item->parentId;
        while (!isset($groups[$parentId])) {
            if (!isset($items[$parentId])) {
                throw new \LogicException(\sprintf('Navigation item "%s" in menu "%s" names the unknown parent "%s".', $item->id, $menu->value, $parentId));
            }

            if (isset($seen[$parentId])) {
                throw new \LogicException(\sprintf('Navigation item "%s" in menu "%s" is part of a parent cycle.', $item->id, $menu->value));
            }

            $seen[$parentId] = true;
            $parentId = $items[$parentId]->parentId;
        }
    }

    /**
     * Visible items by the id of their parent (group or item), in provider order.
     *
     * @param array<string, NavGroup> $groups
     * @param array<string, NavItem>  $items
     *
     * @return array<string, list<NavItem>>
     */
    private function visibleChildren(array $groups, array $items): array
    {
        $byParent = [];
        foreach ($items as $item) {
            $byParent[$item->parentId][] = $item;
        }

        $visible = [];
        $walk = function (string $parentId) use (&$walk, &$visible, $byParent): bool {
            $any = false;
            foreach ($byParent[$parentId] ?? [] as $item) {
                if (null !== $item->grantedAttribute && !$this->authorizationChecker->isGranted($item->grantedAttribute)) {
                    continue;
                }

                /** @var bool $hasVisibleChildren */
                $hasVisibleChildren = $walk($item->id);
                if (!$item->isLink() && !$hasVisibleChildren) {
                    continue;
                }

                $visible[$parentId][] = $item;
                $any = true;
            }

            return $any;
        };

        foreach (array_keys($groups) as $groupId) {
            $walk($groupId);
        }

        return $visible;
    }

    /**
     * @param array<string, list<NavItem>> $visibleChildren
     * @param array<string, mixed>         $routeParameters
     */
    private function matchRoute(array $visibleChildren, string $route, array $routeParameters): ?string
    {
        foreach ($visibleChildren as $children) {
            foreach ($children as $item) {
                if ($item->matches($route, $routeParameters)) {
                    return $item->id;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>         $routeParameters
     * @param array<string, list<NavItem>> $visibleChildren of the primary menu
     */
    private function primaryEntryFor(?string $route, array $routeParameters, ?Menu $area, array $visibleChildren): ?string
    {
        $candidates = [];
        if (null !== $route) {
            foreach (Menu::cases() as $sidebar) {
                $entryId = $sidebar->primaryEntryId();
                if (null === $entryId) {
                    continue;
                }

                [$groups, $items] = $this->collect($sidebar);
                if (null !== $this->matchRoute($this->visibleChildren($groups, $items), $route, $routeParameters)) {
                    $candidates[] = $entryId;
                }
            }
        }

        $areaEntryId = $area?->primaryEntryId();
        if (null !== $areaEntryId) {
            $candidates[] = $areaEntryId;
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

        foreach ($candidates as $entryId) {
            if (isset($visibleIds[$entryId])) {
                return $entryId;
            }
        }

        if ([] === $byPrefix) {
            return null;
        }

        uksort($byPrefix, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return reset($byPrefix);
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
    private function nodes(string $parentId, array $visibleChildren, ?string $activeId, array $onPath, \Collator $collator): array
    {
        $nodes = [];
        foreach ($this->sorted($visibleChildren[$parentId] ?? [], $collator) as $item) {
            $nodes[] = new NavigationNode(
                $item,
                $this->nodes($item->id, $visibleChildren, $activeId, $onPath, $collator),
                $item->id === $activeId,
                isset($onPath[$item->id]),
            );
        }

        return $nodes;
    }

    /**
     * Weight ascending, then translated label in the current locale, then id.
     *
     * @template T of NavGroup|NavItem
     *
     * @param list<T> $entries
     *
     * @return list<T>
     */
    private function sorted(array $entries, \Collator $collator): array
    {
        $labels = [];
        foreach ($entries as $entry) {
            $labels[$entry->id] = $entry->label?->trans($this->translator) ?? '';
        }

        usort(
            $entries,
            static fn (NavGroup|NavItem $a, NavGroup|NavItem $b): int => ($a->weight <=> $b->weight)
                ?: (int) $collator->compare($labels[$a->id], $labels[$b->id])
                ?: $a->id <=> $b->id,
        );

        return $entries;
    }
}
