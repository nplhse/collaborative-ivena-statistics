<?php

declare(strict_types=1);

namespace App\Content\UI\Navigation;

use App\Content\Application\Page\PageNavigationProvider;
use App\Shared\Application\Navigation\Menu\LiteralLabel;
use App\Shared\Application\Navigation\Menu\Menu;
use App\Shared\Application\Navigation\Menu\NavigationProviderInterface;
use App\Shared\Application\Navigation\Menu\NavItem;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Blog and the header pages of the CMS in the primary menu (ADR 035).
 *
 * The header pages come from {@see PageNavigationProvider}, which loads the published pages once per
 * request; a page that does not exist, is not published or is guest-only for a signed-in user is not
 * returned. Page entries link by URL and never become active, as before.
 *
 * @psalm-suppress UnusedClass Tagged via NavigationProviderInterface.
 */
final readonly class ContentNavigationProvider implements NavigationProviderInterface
{
    public function __construct(
        private PageNavigationProvider $pageNavigationProvider,
    ) {
    }

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

        $items = [
            new NavItem(
                id: 'blog',
                parentId: Menu::PRIMARY_GROUP,
                label: new TranslatableMessage('link.blog', [], 'shared'),
                route: 'app_blog_index',
                icon: 'tabler:notes',
                weight: 60,
                areaRoutePrefix: 'app_blog',
            ),
        ];

        foreach ($this->pageNavigationProvider->getHeaderPages() as $position => $link) {
            $items[] = new NavItem(
                id: 'page-'.$link->key->value,
                parentId: Menu::PRIMARY_GROUP,
                label: new LiteralLabel($link->label),
                icon: $link->key->navIcon(),
                weight: 70 + 10 * $position,
                url: $link->url,
            );
        }

        return $items;
    }
}
