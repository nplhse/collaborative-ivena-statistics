<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation;

use App\Shared\Application\Navigation\Contract\NavigationPageLinksProviderInterface;
use App\Shared\Application\Navigation\DTO\SitemapEntry;
use App\Shared\Application\Navigation\DTO\SitemapGroup;
use App\Shared\Application\Navigation\DTO\SitemapPageNode;
use App\Shared\Application\Navigation\DTO\SitemapSection;
use App\Shared\Application\Navigation\Menu\Menu;
use App\Shared\Application\Navigation\Menu\NavBadge;
use App\Shared\Application\Navigation\Menu\NavigationBuilder;
use App\Shared\Application\Navigation\Menu\NavigationNode;
use App\Shared\Application\Navigation\Menu\NavItem;
use App\Shared\Application\Navigation\Tool\ToolRegistry;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The HTML sitemap, arranged from the navigation tree (ADR 035) for the current user.
 *
 * - One section per entry of the primary menu, in its order. Areas with a sidebar menu (Statistics, Explore)
 *   list its groups and nested entries; the Tools area lists the tools of the {@see ToolRegistry} by category.
 * - One section per footer column.
 * - The published content pages that no menu links to, as a tree.
 *
 * Labels, order and permissions come from the tree; this class adds no routes of its own. Every URL is
 * listed once: areas and sidebars first, then the footer, then the page tree, where a page that is already
 * listed is dropped unless it still has unlisted children. Pages outside the menus (account, settings,
 * login) are deliberately not listed; the header reaches them (#1247).
 */
final readonly class SitemapProvider
{
    /**
     * Id of the Tools entry of the primary menu, defined by SharedNavigationProvider (ADR 031).
     */
    private const string TOOLS_ENTRY_ID = 'tools';

    public function __construct(
        private NavigationBuilder $navigationBuilder,
        private NavigationPageLinksProviderInterface $pageLinksProvider,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private ToolRegistry $toolRegistry,
    ) {
    }

    /**
     * @return list<SitemapSection>
     */
    public function getSections(): array
    {
        /** @var array<string, true> $seen */
        $seen = [$this->urlGenerator->generate('app_sitemap') => true];

        $sections = [];
        foreach ($this->navigationBuilder->build(Menu::Primary)->sections as $primarySection) {
            foreach ($primarySection->nodes as $node) {
                $sections[] = $this->areaSection($node, $seen);
            }
        }

        foreach ($this->navigationBuilder->build(Menu::Footer)->sections as $footerSection) {
            $entries = $this->entries($footerSection->nodes, $seen);
            if ([] === $entries) {
                continue;
            }

            $sections[] = new SitemapSection(
                key: 'footer-'.$footerSection->group->id,
                label: $footerSection->group->label?->trans($this->translator) ?? '',
                groups: [new SitemapGroup(null, $entries)],
            );
        }

        $pages = $this->pageEntries($this->pageLinksProvider->visiblePublishedPageTree(), $seen);
        if ([] !== $pages) {
            $sections[] = new SitemapSection(
                key: 'pages',
                label: $this->translator->trans('sitemap.section.pages', [], 'shared'),
                icon: 'tabler:file-text',
                groups: [new SitemapGroup(null, $pages)],
            );
        }

        return $sections;
    }

    /**
     * @param array<string, true> $seen
     */
    private function areaSection(NavigationNode $node, array &$seen): SitemapSection
    {
        $item = $node->item;
        $sidebar = $this->sidebarOf($item);
        $groups = match (true) {
            $sidebar instanceof Menu => $this->sidebarGroups($sidebar, $seen),
            self::TOOLS_ENTRY_ID === $item->id => $this->toolGroups($seen),
            default => [],
        };

        $children = $this->entries($node->children, $seen);
        if ([] !== $children) {
            $groups[] = new SitemapGroup(null, $children);
        }

        $url = $this->href($item);
        if (null !== $url) {
            $seen[$url] = true;
        }

        return new SitemapSection(
            key: $item->id,
            label: $item->label->trans($this->translator),
            url: $url,
            icon: $item->icon,
            groups: $groups,
        );
    }

    private function sidebarOf(NavItem $primaryItem): ?Menu
    {
        foreach (Menu::cases() as $menu) {
            if ($menu->primaryEntryId() === $primaryItem->id) {
                return $menu;
            }
        }

        return null;
    }

    /**
     * @param array<string, true> $seen
     *
     * @return list<SitemapGroup>
     */
    private function sidebarGroups(Menu $sidebar, array &$seen): array
    {
        $groups = [];
        foreach ($this->navigationBuilder->build($sidebar)->sections as $section) {
            $entries = $this->entries($section->nodes, $seen);
            if ([] !== $entries) {
                $groups[] = new SitemapGroup($section->group->label?->trans($this->translator), $entries);
            }
        }

        return $groups;
    }

    /**
     * @param array<string, true> $seen
     *
     * @return list<SitemapGroup>
     */
    private function toolGroups(array &$seen): array
    {
        $beta = new NavBadge(new TranslatableMessage('tools.beta', [], 'shared'));

        $groups = [];
        foreach ($this->toolRegistry->groupedByCategory() as $toolGroup) {
            $entries = [];
            foreach ($toolGroup->tools as $tool) {
                $url = $this->urlGenerator->generate($tool->route, $tool->routeParameters);
                if (isset($seen[$url])) {
                    continue;
                }

                $seen[$url] = true;
                $entries[] = new SitemapEntry(
                    label: $this->translator->trans($tool->titleKey, [], $tool->translationDomain),
                    url: $url,
                    badge: $tool->beta ? $beta : null,
                );
            }

            if ([] !== $entries) {
                $groups[] = new SitemapGroup($this->translator->trans($toolGroup->category->labelKey(), [], 'shared'), $entries);
            }
        }

        return $groups;
    }

    /**
     * @param list<NavigationNode> $nodes
     * @param array<string, true>  $seen
     *
     * @return list<SitemapEntry>
     */
    private function entries(array $nodes, array &$seen): array
    {
        $entries = [];
        foreach ($nodes as $node) {
            $url = $this->href($node->item);
            if (null !== $url && isset($seen[$url])) {
                $url = null;
            }

            if (null !== $url) {
                $seen[$url] = true;
            }

            $children = $this->entries($node->children, $seen);
            if (null === $url && [] === $children) {
                continue;
            }

            $entries[] = new SitemapEntry(
                label: $node->item->label->trans($this->translator),
                url: $url,
                badge: $node->item->badge,
                children: $children,
            );
        }

        return $entries;
    }

    /**
     * @param list<SitemapPageNode> $nodes
     * @param array<string, true>   $seen
     *
     * @return list<SitemapEntry>
     */
    private function pageEntries(array $nodes, array &$seen): array
    {
        $entries = [];
        foreach ($nodes as $node) {
            $listed = isset($seen[$node->url]);
            $seen[$node->url] = true;

            $children = $this->pageEntries($node->children, $seen);
            if ($listed && [] === $children) {
                continue;
            }

            $entries[] = new SitemapEntry(label: $node->label, url: $node->url, children: $children);
        }

        return $entries;
    }

    /**
     * The plain URL of an item: no query parameters are carried over, the sitemap has no filter context.
     */
    private function href(NavItem $item): ?string
    {
        if (null !== $item->url) {
            return $item->url;
        }

        return null === $item->route ? null : $this->urlGenerator->generate($item->route, $item->routeParameters);
    }
}
