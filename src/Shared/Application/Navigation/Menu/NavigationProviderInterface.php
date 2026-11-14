<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Menu;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Extension point for the navigation tree (ADR 035).
 *
 * Contexts contribute groups and items to the named menus without a dependency from Shared on them. The
 * context that owns a menu defines its groups; other contexts attach items by group id. Providers run on
 * every page with a navigation, so they must not query the database; access is expressed per item as an
 * `is_granted` attribute and checked by the builder.
 */
#[AutoconfigureTag(self::TAG)]
interface NavigationProviderInterface
{
    public const string TAG = 'shared.navigation';

    /**
     * @return list<NavGroup>
     */
    public function groups(Menu $menu): array;

    /**
     * @return list<NavItem>
     */
    public function items(Menu $menu): array;
}
