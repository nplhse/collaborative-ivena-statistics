<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Menu;

use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * One entry of a menu (ADR 035).
 *
 * An item with a route (or a ready `url`, for example a CMS page) is a link. An item without both groups
 * the items whose `parentId` names it and is shown only while one of them is visible. `parentId` names a
 * {@see NavGroup} for a top-level entry or another item for a nested one.
 *
 * The item is active when the current route name equals `route` or one of `detailRoutes` and every
 * parameter of `routeParameters` that the current route also has carries the same value (so the Insights
 * dimensions, which share one route, are told apart by `dimension`); an item with only a `url` never
 * becomes active. There is no matching by route prefix, with one exception for the areas of
 * the top navbar: a primary entry may name an `areaRoutePrefix` that marks it while no entry matches
 * exactly. Lower `weight` comes first; equal weights sort by the translated label.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the navigation components.
 */
final readonly class NavItem
{
    /**
     * @param array<string, int|string> $routeParameters
     * @param list<string>              $detailRoutes      further route names that mark this entry as active
     * @param bool                      $sharedFilterState the children are parts of one analysis with one filter drawer;
     *                                                     links between them keep the whole filter state
     */
    public function __construct(
        public string $id,
        public string $parentId,
        public TranslatableInterface $label,
        public ?string $route = null,
        public array $routeParameters = [],
        public ?string $icon = null,
        public ?NavBadge $badge = null,
        public ?string $grantedAttribute = null,
        public array $detailRoutes = [],
        public int $weight = 0,
        public ?string $url = null,
        public ?string $areaRoutePrefix = null,
        public bool $sharedFilterState = false,
    ) {
    }

    /**
     * @param array<string, mixed> $currentRouteParameters
     */
    public function matches(string $route, array $currentRouteParameters): bool
    {
        if (!\in_array($route, $this->claimedRoutes(), true)) {
            return false;
        }

        return array_all(
            $this->routeParameters,
            static fn (int|string $value, string $name): bool => !\array_key_exists($name, $currentRouteParameters)
                || (string) $currentRouteParameters[$name] === (string) $value,
        );
    }

    public function isLink(): bool
    {
        return null !== $this->route || null !== $this->url;
    }

    /**
     * @return list<string> every route name that marks this entry as active
     */
    public function claimedRoutes(): array
    {
        return null === $this->route ? $this->detailRoutes : [$this->route, ...$this->detailRoutes];
    }
}
