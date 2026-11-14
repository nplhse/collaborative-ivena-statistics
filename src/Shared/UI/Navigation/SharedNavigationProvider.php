<?php

declare(strict_types=1);

namespace App\Shared\UI\Navigation;

use App\Shared\Application\Navigation\Menu\Menu;
use App\Shared\Application\Navigation\Menu\NavGroup;
use App\Shared\Application\Navigation\Menu\NavigationProviderInterface;
use App\Shared\Application\Navigation\Menu\NavItem;
use App\Shared\Application\Navigation\Tool\ToolRegistry;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Shared owns the primary menu (ADR 035): its one untitled group and the Tools entry (ADR 031).
 *
 * Weights of the primary entries, kept in steps of ten across contexts: Explore 10, Statistics 20,
 * Import 30, Export 40, Tools 50, Blog 60, content pages from 70.
 *
 * @psalm-suppress UnusedClass Tagged via NavigationProviderInterface.
 */
final readonly class SharedNavigationProvider implements NavigationProviderInterface
{
    public function __construct(
        private ToolRegistry $toolRegistry,
    ) {
    }

    #[\Override]
    public function groups(Menu $menu): array
    {
        return Menu::Primary === $menu ? [new NavGroup(Menu::PRIMARY_GROUP)] : [];
    }

    #[\Override]
    public function items(Menu $menu): array
    {
        if (Menu::Primary !== $menu || !$this->toolRegistry->hasTools()) {
            return [];
        }

        return [
            new NavItem(
                id: 'tools',
                parentId: Menu::PRIMARY_GROUP,
                label: new TranslatableMessage('link.tools', [], 'shared'),
                route: 'app_tools_index',
                icon: 'tabler:tool',
                grantedAttribute: 'ROLE_USER',
                weight: 50,
                areaRoutePrefix: 'app_tools',
            ),
        ];
    }
}
