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
 * Shared owns the primary and the footer menu (ADR 035): the untitled primary group and the Tools entry
 * (ADR 031), the four footer columns and the footer links to its own pages (sitemap, cookie settings).
 *
 * Weights of the primary entries, kept in steps of ten across contexts: Explore 10, Statistics 20,
 * Import 30, Tools 50 (40 was Export, now a tool, #1633), Blog 60, content pages from 70. Footer columns: project 10, help 20,
 * legal 30, more 40.
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
        return match ($menu) {
            Menu::Primary => [new NavGroup(Menu::PRIMARY_GROUP)],
            Menu::Footer => [
                new NavGroup(Menu::FOOTER_GROUP_PROJECT, new TranslatableMessage('footer.column.project', [], 'shared'), 10),
                new NavGroup(Menu::FOOTER_GROUP_HELP, new TranslatableMessage('footer.column.help', [], 'shared'), 20),
                new NavGroup(Menu::FOOTER_GROUP_LEGAL, new TranslatableMessage('footer.column.legal', [], 'shared'), 30),
                new NavGroup(Menu::FOOTER_GROUP_MORE, new TranslatableMessage('footer.column.more', [], 'shared'), 40),
            ],
            default => [],
        };
    }

    #[\Override]
    public function items(Menu $menu): array
    {
        return match ($menu) {
            Menu::Primary => $this->primaryItems(),
            Menu::Footer => $this->footerItems(),
            default => [],
        };
    }

    /**
     * @return list<NavItem>
     */
    private function footerItems(): array
    {
        return [
            new NavItem(
                id: 'sitemap',
                parentId: Menu::FOOTER_GROUP_MORE,
                label: new TranslatableMessage('link.sitemap', [], 'shared'),
                route: 'app_sitemap',
                weight: 10,
            ),
            new NavItem(
                id: 'cookie-preferences',
                parentId: Menu::FOOTER_GROUP_MORE,
                label: new TranslatableMessage('link.cookie_preferences', [], 'shared'),
                route: 'app_cookie_preferences',
                weight: 20,
            ),
        ];
    }

    /**
     * @return list<NavItem>
     */
    private function primaryItems(): array
    {
        if (!$this->toolRegistry->hasTools()) {
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
