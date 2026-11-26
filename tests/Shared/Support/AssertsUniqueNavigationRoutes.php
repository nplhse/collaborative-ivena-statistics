<?php

declare(strict_types=1);

namespace App\Tests\Shared\Support;

use App\Shared\Application\Navigation\Menu\Menu;
use App\Shared\Application\Navigation\Menu\NavigationBuilder;

/**
 * ADR 035: a route name may mark at most one entry per menu as active, and area route prefixes of the
 * top navbar must not overlap. Use this in a test that builds
 * the menus with all real providers, so a new entry that claims a taken route fails the build.
 */
trait AssertsUniqueNavigationRoutes
{
    protected static function assertUniqueNavigationRoutes(NavigationBuilder $builder, Menu ...$menus): void
    {
        $duplicates = [];
        foreach ([] === $menus ? Menu::cases() : $menus as $menu) {
            foreach ($builder->duplicateRouteClaims($menu) as $route => $ids) {
                $duplicates[] = \sprintf('%s: route "%s" is claimed by %s', $menu->value, $route, implode(', ', $ids));
            }

            foreach ($builder->overlappingAreaRoutePrefixes($menu) as $overlap) {
                $duplicates[] = \sprintf('%s: area route prefix %s', $menu->value, $overlap);
            }
        }

        self::assertSame([], $duplicates, 'Every route name may mark at most one navigation entry per menu as active.');
    }
}
