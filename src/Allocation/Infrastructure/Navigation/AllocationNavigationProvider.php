<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Navigation;

use App\Allocation\Infrastructure\Security\Voter\ExportVoter;
use App\Shared\Application\Navigation\Menu\Menu;
use App\Shared\Application\Navigation\Menu\NavigationProviderInterface;
use App\Shared\Application\Navigation\Menu\NavItem;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Explore and Export in the primary menu (ADR 035).
 *
 * @psalm-suppress UnusedClass Tagged via NavigationProviderInterface.
 */
final readonly class AllocationNavigationProvider implements NavigationProviderInterface
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
                id: 'explore',
                parentId: Menu::PRIMARY_GROUP,
                label: new TranslatableMessage('link.explore', [], 'shared'),
                route: 'app_explore_index',
                icon: 'tabler:compass',
                grantedAttribute: 'ROLE_PARTICIPANT',
                weight: 10,
                areaRoutePrefix: 'app_explore',
            ),
            new NavItem(
                id: 'export',
                parentId: Menu::PRIMARY_GROUP,
                label: new TranslatableMessage('link.export', [], 'shared'),
                route: 'app_hospitals_export_allocations',
                icon: 'tabler:database-export',
                grantedAttribute: ExportVoter::EXPORT,
                weight: 40,
                areaRoutePrefix: 'app_hospitals_export',
            ),
        ];
    }
}
