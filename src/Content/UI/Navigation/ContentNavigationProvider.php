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
 * Blog and the header pages of the CMS in the primary menu; blog, activity and the CMS pages in the
 * footer columns that Shared defines (ADR 035).
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

    /**
     * CMS pages of the footer by column, in display order. A page that does not exist or is not published
     * is left out; a column without links is not shown.
     */
    private const array FOOTER_PAGES = [
        Menu::FOOTER_GROUP_PROJECT => ['about', 'features'],
        Menu::FOOTER_GROUP_HELP => ['help'],
        Menu::FOOTER_GROUP_LEGAL => ['imprint', 'privacy', 'terms'],
    ];

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
        $items = [];
        foreach (self::FOOTER_PAGES as $groupId => $pageKeys) {
            foreach ($pageKeys as $position => $pageKey) {
                $links = $this->pageNavigationProvider->linksForKeys($pageKey);
                if ([] === $links && 'help' === $pageKey) {
                    // The help section replaces the FAQ (#596); until it is published the FAQ keeps its place.
                    $pageKey = 'faq';
                    $links = $this->pageNavigationProvider->linksForKeys($pageKey);
                }

                foreach ($links as $link) {
                    $items[] = new NavItem(
                        id: 'page-'.$pageKey,
                        parentId: $groupId,
                        label: new LiteralLabel($link['label']),
                        weight: 10 * ($position + 1),
                        url: $link['url'],
                    );
                }
            }
        }

        $items[] = new NavItem(
            id: 'blog',
            parentId: Menu::FOOTER_GROUP_PROJECT,
            label: new TranslatableMessage('link.blog', [], 'shared'),
            route: 'app_blog_index',
            weight: 30,
        );
        $items[] = new NavItem(
            id: 'activity',
            parentId: Menu::FOOTER_GROUP_PROJECT,
            label: new TranslatableMessage('link.activity', [], 'shared'),
            route: 'app_activity_timeline',
            grantedAttribute: 'ROLE_USER',
            weight: 40,
        );

        return $items;
    }

    /**
     * @return list<NavItem>
     */
    private function primaryItems(): array
    {
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
