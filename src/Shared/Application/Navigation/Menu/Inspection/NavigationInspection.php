<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Menu\Inspection;

/**
 * The result of the admin navigation inspector (#1237): every menu and the routes of each area that no
 * entry claims.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the admin navigation template.
 */
final readonly class NavigationInspection
{
    /**
     * @param list<InspectedMenu>                                        $menus
     * @param array<string, array{prefix: string, routes: list<string>}> $unclaimedAreaRoutes primary entry id => its prefix and the
     *                                                                                        GET routes with that prefix no entry claims
     * @param array<string, string>                                      $routeParameters     of the checked route
     */
    public function __construct(
        public array $menus,
        public array $unclaimedAreaRoutes,
        public ?string $userIdentifier,
        public ?string $route,
        public array $routeParameters,
    ) {
    }

    public function warningCount(): int
    {
        return array_sum(array_map(static fn (InspectedMenu $menu): int => $menu->warningCount(), $this->menus));
    }
}
