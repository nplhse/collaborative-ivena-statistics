<?php

declare(strict_types=1);

namespace App\User\UI\Navigation;

use App\Shared\Application\Navigation\Menu\Menu;
use App\Shared\Application\Navigation\Menu\NavigationProviderInterface;
use App\Shared\Application\Navigation\Menu\NavItem;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The users directory in the organisation group of the explore sidebar (ADR 035).
 *
 * The list needs ROLE_PARTICIPANT like Explore. A profile page may also be the user's own profile opened
 * without that role; the entry is then hidden, and with it the whole sidebar.
 *
 * @psalm-suppress UnusedClass Tagged via NavigationProviderInterface.
 */
final readonly class UserNavigationProvider implements NavigationProviderInterface
{
    #[\Override]
    public function groups(Menu $menu): array
    {
        return [];
    }

    #[\Override]
    public function items(Menu $menu): array
    {
        if (Menu::Explore !== $menu) {
            return [];
        }

        return [
            new NavItem(
                id: 'users',
                parentId: Menu::EXPLORE_GROUP_ORGANISATION,
                label: new TranslatableMessage('link.users', [], 'shared'),
                route: 'app_explore_user_list',
                icon: 'tabler:users',
                grantedAttribute: 'ROLE_PARTICIPANT',
                detailRoutes: ['app_explore_user_show', 'app_explore_user_activity'],
                weight: 40,
            ),
        ];
    }
}
