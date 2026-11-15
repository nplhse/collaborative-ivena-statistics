<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Menu;

/**
 * The groups and items all providers contribute to one menu, with the provider of each and the problems
 * found while merging them (ADR 035).
 *
 * {@see NavigationBuilder} rejects a menu with problems; the admin navigation inspector (#1237) shows them
 * as warnings. A duplicate id keeps the first registration; an item with an unknown parent or in a parent
 * cycle is an orphan that never becomes visible.
 */
final readonly class NavigationContributions
{
    /**
     * @param array<string, NavGroup>     $groups    by id, in provider order
     * @param array<string, NavItem>      $items     by id, in provider order
     * @param array<string, class-string> $providers id => class of the provider that contributed it
     * @param list<string>                $problems  in the order they were found
     * @param list<string>                $orphans   ids of items that do not reach a group
     */
    private function __construct(
        public Menu $menu,
        public array $groups,
        public array $items,
        public array $providers,
        public array $problems,
        public array $orphans,
    ) {
    }

    /**
     * @param iterable<NavigationProviderInterface> $providers
     */
    public static function collect(iterable $providers, Menu $menu): self
    {
        $groups = [];
        $items = [];
        $providerOf = [];
        $problems = [];
        $duplicate = static fn (string $id): string => \sprintf('Navigation id "%s" is registered more than once in menu "%s".', $id, $menu->value);

        foreach ($providers as $provider) {
            foreach ($provider->groups($menu) as $group) {
                if (isset($providerOf[$group->id])) {
                    $problems[] = $duplicate($group->id);
                    continue;
                }
                $groups[$group->id] = $group;
                $providerOf[$group->id] = $provider::class;
            }

            foreach ($provider->items($menu) as $item) {
                if (isset($providerOf[$item->id])) {
                    $problems[] = $duplicate($item->id);
                    continue;
                }
                $items[$item->id] = $item;
                $providerOf[$item->id] = $provider::class;
            }
        }

        $orphans = [];
        foreach ($items as $item) {
            $problem = self::parentProblem($item, $groups, $items, $menu);
            if (null !== $problem) {
                $problems[] = $problem;
                $orphans[] = $item->id;
            }
        }

        return new self($menu, $groups, $items, $providerOf, $problems, $orphans);
    }

    /**
     * Why each group and item is shown or not, for the given permission check.
     *
     * An item is visible when its own `grantedAttribute` passes (or it has none), its parent is visible and,
     * for an item without route or URL, at least one child is visible. A group is visible with at least one
     * visible item.
     *
     * @param callable(string): bool $isGranted
     *
     * @return array<string, NavigationVisibility> by id of group or item
     */
    public function visibility(callable $isGranted): array
    {
        $byParent = [];
        foreach ($this->items as $item) {
            $byParent[$item->parentId][] = $item;
        }

        $visibility = [];
        foreach ($this->orphans as $id) {
            $visibility[$id] = NavigationVisibility::Orphaned;
        }

        $hide = function (string $parentId) use (&$hide, &$visibility, $byParent): void {
            foreach ($byParent[$parentId] ?? [] as $item) {
                $visibility[$item->id] ??= NavigationVisibility::HiddenParent;
                $hide($item->id);
            }
        };

        $walk = function (string $parentId) use (&$walk, &$visibility, $byParent, $hide, $isGranted): bool {
            $any = false;
            foreach ($byParent[$parentId] ?? [] as $item) {
                if (null !== $item->grantedAttribute && !$isGranted($item->grantedAttribute)) {
                    $visibility[$item->id] = NavigationVisibility::Denied;
                    $hide($item->id);
                    continue;
                }

                /** @var bool $hasVisibleChildren */
                $hasVisibleChildren = $walk($item->id);
                if (!$item->isLink() && !$hasVisibleChildren) {
                    $visibility[$item->id] = NavigationVisibility::NoVisibleChildren;
                    continue;
                }

                $visibility[$item->id] = NavigationVisibility::Visible;
                $any = true;
            }

            return $any;
        };

        foreach (array_keys($this->groups) as $groupId) {
            $visibility[$groupId] = $walk($groupId) ? NavigationVisibility::Visible : NavigationVisibility::Empty;
        }

        return $visibility;
    }

    /**
     * Route names that more than one item claims with the same route parameters, regardless of permissions.
     *
     * @return array<string, list<string>> route name, with the parameters if any => ids of the items that claim it
     */
    public function duplicateRouteClaims(): array
    {
        $claims = [];
        foreach ($this->items as $item) {
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
     * Pairs of area route prefixes where one starts with the other, so a route could fall into two areas,
     * regardless of permissions.
     *
     * @return list<string> human-readable descriptions of the overlaps
     */
    public function overlappingAreaRoutePrefixes(): array
    {
        $prefixes = [];
        foreach ($this->items as $item) {
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
     * @param array<string, NavGroup> $groups
     * @param array<string, NavItem>  $items
     */
    private static function parentProblem(NavItem $item, array $groups, array $items, Menu $menu): ?string
    {
        $seen = [$item->id => true];
        $parentId = $item->parentId;
        while (!isset($groups[$parentId])) {
            if (!isset($items[$parentId])) {
                return \sprintf('Navigation item "%s" in menu "%s" names the unknown parent "%s".', $item->id, $menu->value, $parentId);
            }

            if (isset($seen[$parentId])) {
                return \sprintf('Navigation item "%s" in menu "%s" is part of a parent cycle.', $item->id, $menu->value);
            }

            $seen[$parentId] = true;
            $parentId = $items[$parentId]->parentId;
        }

        return null;
    }
}
