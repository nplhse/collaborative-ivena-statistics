<?php

declare(strict_types=1);

namespace App\Statistics\UI\Navigation;

use App\Shared\Application\Navigation\Menu\Menu;
use App\Shared\Application\Navigation\Menu\NavigationProviderInterface;
use App\Shared\Application\Navigation\Menu\NavItem;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The Statistics entry of the primary menu (ADR 035). Its id is the primary entry of the statistics
 * sidebar menu ({@see Menu::primaryEntryId()}).
 *
 * @psalm-suppress UnusedClass Tagged via NavigationProviderInterface.
 */
final readonly class StatisticsNavigationProvider implements NavigationProviderInterface
{
    #[\Override]
    public function groups(Menu $menu): array
    {
        return [];
    }

    #[\Override]
    public function items(Menu $menu): array
    {
        if (Menu::Primary !== $menu) {
            return [];
        }

        return [
            new NavItem(
                id: 'statistics',
                parentId: Menu::PRIMARY_GROUP,
                label: new TranslatableMessage('link.statistics', [], 'shared'),
                route: 'app_stats_dashboard',
                icon: 'tabler:graph',
                grantedAttribute: 'ROLE_USER',
                weight: 20,
                areaRoutePrefix: 'app_stats',
            ),
        ];
    }
}
