<?php

declare(strict_types=1);

namespace App\Import\UI\Navigation;

use App\Shared\Application\Navigation\Menu\Menu;
use App\Shared\Application\Navigation\Menu\NavigationProviderInterface;
use App\Shared\Application\Navigation\Menu\NavItem;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The Import entry of the primary menu (ADR 035).
 *
 * @psalm-suppress UnusedClass Tagged via NavigationProviderInterface.
 */
final readonly class ImportNavigationProvider implements NavigationProviderInterface
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
                id: 'import',
                parentId: Menu::PRIMARY_GROUP,
                label: new TranslatableMessage('link.import', [], 'shared'),
                route: 'app_import_index',
                icon: 'tabler:database-import',
                grantedAttribute: 'ROLE_PARTICIPANT',
                weight: 30,
                areaRoutePrefix: 'app_import',
            ),
        ];
    }
}
