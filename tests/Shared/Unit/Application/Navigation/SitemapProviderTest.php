<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Application\Navigation;

use App\Shared\Application\Navigation\Contract\NavigationPageLinksProviderInterface;
use App\Shared\Application\Navigation\DTO\SitemapEntry;
use App\Shared\Application\Navigation\DTO\SitemapPageNode;
use App\Shared\Application\Navigation\Menu\Menu;
use App\Shared\Application\Navigation\Menu\NavBadge;
use App\Shared\Application\Navigation\Menu\NavGroup;
use App\Shared\Application\Navigation\Menu\NavigationBuilder;
use App\Shared\Application\Navigation\Menu\NavigationProviderInterface;
use App\Shared\Application\Navigation\Menu\NavItem;
use App\Shared\Application\Navigation\SitemapProvider;
use App\Shared\Application\Navigation\Tool\Tool;
use App\Shared\Application\Navigation\Tool\ToolCategory;
use App\Shared\Application\Navigation\Tool\ToolProviderInterface;
use App\Shared\Application\Navigation\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The sitemap is arranged from a small fake navigation tree. The translator returns the ids, the URL
 * generator returns "/<route>" plus the parameters as query, so every line of the expected outlines can be
 * followed from the tree below.
 */
final class SitemapProviderTest extends TestCase
{
    public function testArrangesAreasSidebarGroupsFooterAndPagesFromTheTree(): void
    {
        self::assertSame([
            // Primary entries in their weight order; Statistics lists its sidebar, Tools the registry.
            'statistics: Statistics </r_stats> [tabler:graph]',
            '  Dashboard </r_stats>',
            '  Reports </r_reports>',
            '  [Comparisons]',
            '  Insights',
            '    Overview </r_insights>',
            '    Dimensions',
            '      Dimension A </r_insights?dimension=a>',
            '      Dimension B </r_insights?dimension=b>',
            '  Benchmarking </r_bench> (Beta)',
            '  [Participants only]',
            '  Case flow </r_flow>',
            'import: Import </r_import> [tabler:database-import]',
            'tools: Tools </app_tools_index> [tabler:tool]',
            '  [tools.category.matching]',
            '  Matching review </r_matching> (tools.beta)',
            'blog: Blog </r_blog>',
            // Footer columns: Blog is already listed as an area, the sitemap itself is never listed.
            'footer-project: Project',
            '  Activity </r_activity>',
            'footer-legal: Legal',
            '  Imprint </imprint>',
            'footer-more: More',
            '  Cookie settings </r_cookies>',
            // Pages: Imprint is in the footer and has no children, so it is dropped; Guides keeps its subtree.
            'pages: sitemap.section.pages [tabler:file-text]',
            '  Guides </guides>',
            '    Guide A </guides/a>',
            '  About </about>',
        ], $this->outline($this->provider([$this->tree()])));
    }

    public function testPermissionsOfTheTreeDecideWhatIsListed(): void
    {
        $outline = $this->outline($this->provider([$this->tree()], granted: ['ROLE_USER']));

        self::assertNotContains('  [Participants only]', $outline, 'A group without visible entries is dropped.');
        self::assertNotContains('  Case flow </r_flow>', $outline);
        self::assertNotContains('import: Import </r_import> [tabler:database-import]', $outline, 'Import needs ROLE_PARTICIPANT.');
        self::assertContains('  Benchmarking </r_bench> (Beta)', $outline);

        $guestOutline = $this->outline($this->provider([$this->tree()], granted: []));

        self::assertSame([
            'blog: Blog </r_blog>',
            'footer-legal: Legal',
            '  Imprint </imprint>',
            'footer-more: More',
            '  Cookie settings </r_cookies>',
            'pages: sitemap.section.pages [tabler:file-text]',
            '  Guides </guides>',
            '    Guide A </guides/a>',
            '  About </about>',
        ], $guestOutline, 'Guests see neither Statistics, Import nor Tools, and Activity needs ROLE_USER.');
    }

    public function testAnEntryOfAnotherProviderIsListedWithoutFurtherChanges(): void
    {
        $other = $this->navigationProvider([
            Menu::Statistics->value => [
                'groups' => [],
                'items' => [new NavItem('closures', 'comparisons', new TranslatableMessage('Closures'), route: 'r_closures', weight: 50)],
            ],
        ]);

        $outline = $this->outline($this->provider([$this->tree(), $other]));
        $benchmarking = array_search('  Benchmarking </r_bench> (Beta)', $outline, true);

        self::assertSame('  Closures </r_closures>', $outline[$benchmarking + 1]);
    }

    public function testAnEntryThatIsAlreadyListedOnlyHeadsItsChildren(): void
    {
        $tree = $this->navigationProvider([
            Menu::Primary->value => [
                'groups' => [new NavGroup(Menu::PRIMARY_GROUP)],
                'items' => [new NavItem('explore', Menu::PRIMARY_GROUP, new TranslatableMessage('Explore'), route: 'r_explore')],
            ],
            Menu::Explore->value => [
                'groups' => [new NavGroup('start')],
                'items' => [
                    new NavItem('overview', 'start', new TranslatableMessage('Overview'), route: 'r_explore', weight: -100),
                    new NavItem('again', 'start', new TranslatableMessage('Again'), route: 'r_explore'),
                    new NavItem('again-child', 'again', new TranslatableMessage('Child'), route: 'r_child'),
                    new NavItem('twice', 'start', new TranslatableMessage('Twice'), route: 'r_explore', weight: 10),
                ],
            ],
        ]);

        self::assertSame([
            'explore: Explore </r_explore>',
            '  Overview </r_explore>',
            '  Again',
            '    Child </r_child>',
        ], \array_slice($this->outline($this->provider([$tree])), 0, 4), 'The first entry with a URL keeps the link, later ones lose it and are dropped without children.');
    }

    private function tree(): NavigationProviderInterface
    {
        $beta = new NavBadge(new TranslatableMessage('Beta'));

        return $this->navigationProvider([
            Menu::Primary->value => [
                'groups' => [new NavGroup(Menu::PRIMARY_GROUP)],
                'items' => [
                    new NavItem('blog', Menu::PRIMARY_GROUP, new TranslatableMessage('Blog'), route: 'r_blog', weight: 60),
                    new NavItem('tools', Menu::PRIMARY_GROUP, new TranslatableMessage('Tools'), route: 'app_tools_index', icon: 'tabler:tool', grantedAttribute: 'ROLE_USER', weight: 50),
                    new NavItem('import', Menu::PRIMARY_GROUP, new TranslatableMessage('Import'), route: 'r_import', icon: 'tabler:database-import', grantedAttribute: 'ROLE_PARTICIPANT', weight: 30),
                    new NavItem('statistics', Menu::PRIMARY_GROUP, new TranslatableMessage('Statistics'), route: 'r_stats', icon: 'tabler:graph', grantedAttribute: 'ROLE_USER', weight: 20),
                ],
            ],
            Menu::Statistics->value => [
                'groups' => [
                    new NavGroup('overview', null, 10),
                    new NavGroup('comparisons', new TranslatableMessage('Comparisons'), 20),
                    new NavGroup('participants', new TranslatableMessage('Participants only'), 30),
                ],
                'items' => [
                    new NavItem('reports', 'overview', new TranslatableMessage('Reports'), route: 'r_reports'),
                    new NavItem('dashboard', 'overview', new TranslatableMessage('Dashboard'), route: 'r_stats', weight: -100),
                    new NavItem('insights', 'comparisons', new TranslatableMessage('Insights'), weight: 10),
                    new NavItem('insights-overview', 'insights', new TranslatableMessage('Overview'), route: 'r_insights', weight: -100),
                    new NavItem('dimensions', 'insights', new TranslatableMessage('Dimensions')),
                    new NavItem('dimension-b', 'dimensions', new TranslatableMessage('Dimension B'), route: 'r_insights', routeParameters: ['dimension' => 'b']),
                    new NavItem('dimension-a', 'dimensions', new TranslatableMessage('Dimension A'), route: 'r_insights', routeParameters: ['dimension' => 'a']),
                    new NavItem('benchmarking', 'comparisons', new TranslatableMessage('Benchmarking'), route: 'r_bench', badge: $beta, weight: 20),
                    new NavItem('case-flow', 'participants', new TranslatableMessage('Case flow'), route: 'r_flow', grantedAttribute: 'ROLE_PARTICIPANT'),
                ],
            ],
            Menu::Footer->value => [
                'groups' => [
                    new NavGroup(Menu::FOOTER_GROUP_PROJECT, new TranslatableMessage('Project'), 10),
                    new NavGroup(Menu::FOOTER_GROUP_LEGAL, new TranslatableMessage('Legal'), 30),
                    new NavGroup(Menu::FOOTER_GROUP_MORE, new TranslatableMessage('More'), 40),
                ],
                'items' => [
                    new NavItem('footer-blog', Menu::FOOTER_GROUP_PROJECT, new TranslatableMessage('Blog'), route: 'r_blog'),
                    new NavItem('activity', Menu::FOOTER_GROUP_PROJECT, new TranslatableMessage('Activity'), route: 'r_activity', grantedAttribute: 'ROLE_USER'),
                    new NavItem('imprint', Menu::FOOTER_GROUP_LEGAL, new TranslatableMessage('Imprint'), url: '/imprint'),
                    new NavItem('sitemap', Menu::FOOTER_GROUP_MORE, new TranslatableMessage('Sitemap'), route: 'app_sitemap', weight: 10),
                    new NavItem('cookies', Menu::FOOTER_GROUP_MORE, new TranslatableMessage('Cookie settings'), route: 'r_cookies', weight: 20),
                ],
            ],
        ]);
    }

    /**
     * @param list<NavigationProviderInterface> $navigationProviders
     * @param list<string>|null                 $granted             null grants everything
     */
    private function provider(array $navigationProviders, ?array $granted = null): SitemapProvider
    {
        $translator = $this->translator();
        $authorizationChecker = new readonly class($granted) implements AuthorizationCheckerInterface {
            /**
             * @param list<string>|null $granted
             */
            public function __construct(private ?array $granted)
            {
            }

            #[\Override]
            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                return null === $this->granted || \in_array($attribute, $this->granted, true);
            }
        };

        $pageLinks = new readonly class implements NavigationPageLinksProviderInterface {
            #[\Override]
            public function linksForKeys(string ...$keys): array
            {
                return [];
            }

            #[\Override]
            public function visiblePublishedPageTree(): array
            {
                return [
                    new SitemapPageNode('/imprint', 'Imprint'),
                    new SitemapPageNode('/guides', 'Guides', [new SitemapPageNode('/guides/a', 'Guide A')]),
                    new SitemapPageNode('/about', 'About'),
                ];
            }
        };

        $tools = new readonly class($granted) implements ToolProviderInterface {
            /**
             * @param list<string>|null $granted
             */
            public function __construct(private ?array $granted)
            {
            }

            #[\Override]
            public function tools(): array
            {
                if (null !== $this->granted && !\in_array('ROLE_USER', $this->granted, true)) {
                    return [];
                }

                return [new Tool('matching', 'Matching review', 'description', 'tools', 'r_matching', 'tabler:list-check', ToolCategory::Matching, beta: true)];
            }
        };

        return new SitemapProvider(
            new NavigationBuilder($navigationProviders, $authorizationChecker, $translator),
            $pageLinks,
            $this->urlGenerator(),
            $translator,
            new ToolRegistry([$tools], $translator),
        );
    }

    /**
     * @param array<string, array{groups: list<NavGroup>, items: list<NavItem>}> $trees keyed by menu value
     */
    private function navigationProvider(array $trees): NavigationProviderInterface
    {
        return new readonly class($trees) implements NavigationProviderInterface {
            /**
             * @param array<string, array{groups: list<NavGroup>, items: list<NavItem>}> $trees
             */
            public function __construct(private array $trees)
            {
            }

            #[\Override]
            public function groups(Menu $menu): array
            {
                return $this->trees[$menu->value]['groups'] ?? [];
            }

            #[\Override]
            public function items(Menu $menu): array
            {
                return $this->trees[$menu->value]['items'] ?? [];
            }
        };
    }

    private function translator(): TranslatorInterface
    {
        return new readonly class implements TranslatorInterface {
            /**
             * @param array<string, mixed> $parameters
             */
            #[\Override]
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id;
            }

            #[\Override]
            public function getLocale(): string
            {
                return 'en';
            }
        };
    }

    private function urlGenerator(): UrlGeneratorInterface
    {
        return new class implements UrlGeneratorInterface {
            private RequestContext $context;

            public function __construct()
            {
                $this->context = new RequestContext();
            }

            /**
             * @param array<string, mixed> $parameters
             */
            #[\Override]
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return '/'.$name.([] === $parameters ? '' : '?'.http_build_query($parameters));
            }

            #[\Override]
            public function setContext(RequestContext $context): void
            {
                $this->context = $context;
            }

            #[\Override]
            public function getContext(): RequestContext
            {
                return $this->context;
            }
        };
    }

    /**
     * Sections as lines: "key: Label <url> [icon]", "[Group]" for a group label, one line per entry
     * ("Label <url> (badge)"), children indented by two spaces.
     *
     * @return list<string>
     */
    private function outline(SitemapProvider $provider): array
    {
        $lines = [];
        foreach ($provider->getSections() as $section) {
            $lines[] = $section->key.': '.$section->label
                .(null === $section->url ? '' : ' <'.$section->url.'>')
                .(null === $section->icon ? '' : ' ['.$section->icon.']');
            foreach ($section->groups as $group) {
                if (null !== $group->label) {
                    $lines[] = '  ['.$group->label.']';
                }

                $lines = [...$lines, ...$this->entryLines($group->entries, 1)];
            }
        }

        return $lines;
    }

    /**
     * @param list<SitemapEntry> $entries
     *
     * @return list<string>
     */
    private function entryLines(array $entries, int $depth): array
    {
        $lines = [];
        foreach ($entries as $entry) {
            $lines[] = str_repeat('  ', $depth).$entry->label
                .(null === $entry->url ? '' : ' <'.$entry->url.'>')
                .(null === $entry->badge ? '' : ' ('.$entry->badge->label->trans($this->translator()).')');
            $lines = [...$lines, ...$this->entryLines($entry->children, $depth + 1)];
        }

        return $lines;
    }
}
