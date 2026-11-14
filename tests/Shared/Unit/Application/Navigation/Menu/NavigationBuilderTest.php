<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Application\Navigation\Menu;

use App\Shared\Application\Navigation\Menu\LiteralLabel;
use App\Shared\Application\Navigation\Menu\Menu;
use App\Shared\Application\Navigation\Menu\NavBadge;
use App\Shared\Application\Navigation\Menu\NavBadgeColor;
use App\Shared\Application\Navigation\Menu\NavGroup;
use App\Shared\Application\Navigation\Menu\NavigationBuilder;
use App\Shared\Application\Navigation\Menu\NavigationMenu;
use App\Shared\Application\Navigation\Menu\NavigationNode;
use App\Shared\Application\Navigation\Menu\NavigationProviderInterface;
use App\Shared\Application\Navigation\Menu\NavigationSection;
use App\Shared\Application\Navigation\Menu\NavItem;
use App\Tests\Shared\Support\AssertsUniqueNavigationRoutes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

final class NavigationBuilderTest extends TestCase
{
    use AssertsUniqueNavigationRoutes;

    public function testEntriesAreSortedByWeightThenTranslatedLabelThenId(): void
    {
        $builder = $this->builder([
            Menu::Statistics->value => [
                'groups' => [$this->group('later', 10), $this->group('first', 0)],
                'items' => [
                    $this->item('top', 'first', 'Top-Listen', route: 'r_top'),
                    $this->item('reports', 'first', 'Berichte', route: 'r_reports'),
                    $this->item('dashboard', 'first', 'Übersicht', route: 'r_dashboard', weight: -100),
                    $this->item('aerzte', 'first', 'Ärzte', route: 'r_aerzte'),
                    $this->item('x2', 'later', 'Gleich', route: 'r_x2'),
                    $this->item('x1', 'later', 'Gleich', route: 'r_x1'),
                ],
            ],
        ], locale: 'de');

        $menu = $builder->build(Menu::Statistics);

        self::assertSame(['first', 'later'], $this->sectionIds($menu), 'Group weight 0 comes before 10.');
        self::assertSame(
            ['dashboard', 'aerzte', 'reports', 'top'],
            $this->nodeIds($menu->sections[0]->nodes),
            'Weight -100 first, then German collation: "Ärzte" sorts with A, before "Berichte".',
        );
        self::assertSame(['x1', 'x2'], $this->nodeIds($menu->sections[1]->nodes), 'Equal weight and label fall back to the id.');
    }

    public function testChildrenAreSortedOnEveryLevel(): void
    {
        $builder = $this->builder([
            Menu::Statistics->value => [
                'groups' => [$this->group('closures')],
                'items' => [
                    $this->item('closures-root', 'closures', 'Schließungen'),
                    $this->item('events', 'closures-root', 'Ereignisse', route: 'r_events', weight: 30),
                    $this->item('overview', 'closures-root', 'Übersicht', route: 'r_overview', weight: 10),
                    $this->item('duration', 'closures-root', 'Dauer', route: 'r_duration', weight: 20),
                ],
            ],
        ]);

        $root = $builder->build(Menu::Statistics)->sections[0]->nodes[0];

        self::assertSame(['overview', 'duration', 'events'], $this->nodeIds($root->children), 'Distinct weights give a fixed order regardless of the label.');
    }

    public function testEntriesWithoutPermissionAreHiddenWithTheirChildrenAndEmptyParentsAndGroupsDisappear(): void
    {
        $builder = $this->builder([
            Menu::Statistics->value => [
                'groups' => [$this->group('overview'), $this->group('clinical'), $this->group('capacity')],
                'items' => [
                    $this->item('dashboard', 'overview', 'Übersicht', route: 'r_dashboard'),
                    $this->item('encounter', 'clinical', 'Behandlungsdaten', granted: 'ROLE_ENCOUNTER_BETA'),
                    $this->item('encounter-overview', 'encounter', 'Übersicht', route: 'r_encounter'),
                    $this->item('closures', 'capacity', 'Schließungen'),
                    $this->item('closures-overview', 'closures', 'Übersicht', route: 'r_closures', granted: 'ROLE_CLOSURE_BETA'),
                ],
            ],
        ], granted: []);

        $menu = $builder->build(Menu::Statistics);

        self::assertSame(['overview'], $this->sectionIds($menu), 'Both beta groups lose their only visible entry.');
        self::assertSame(['dashboard'], $this->nodeIds($menu->sections[0]->nodes));
    }

    public function testGrantedEntriesStayVisible(): void
    {
        $builder = $this->builder([
            Menu::Statistics->value => [
                'groups' => [$this->group('clinical')],
                'items' => [
                    $this->item('encounter', 'clinical', 'Behandlungsdaten', granted: 'ROLE_ENCOUNTER_BETA', badge: new NavBadge(new TranslatableMessage('Beta'), NavBadgeColor::Azure)),
                    $this->item('encounter-overview', 'encounter', 'Übersicht', route: 'r_encounter'),
                ],
            ],
        ], granted: ['ROLE_ENCOUNTER_BETA']);

        $node = $builder->build(Menu::Statistics)->sections[0]->nodes[0];

        self::assertSame('encounter', $node->item->id);
        self::assertSame('bg-azure-lt', $node->item->badge?->color->cssClass());
        self::assertSame(['encounter-overview'], $this->nodeIds($node->children));
    }

    public function testTheActivePathRunsFromTheGroupDownToTheEntryOfTheExactRoute(): void
    {
        $builder = $this->builder([Menu::Statistics->value => $this->statisticsTree()]);

        $menu = $builder->build(Menu::Statistics, 'app_stats_insights_dimension');

        self::assertSame(['allocations', 'insights', 'dimensions', 'indications'], $menu->activePath);
        self::assertSame('indications', $menu->activeId());

        $allocations = $menu->sections[1];
        self::assertTrue($allocations->onActivePath);
        self::assertFalse($menu->sections[0]->onActivePath);

        $insights = $allocations->nodes[0];
        $dimensions = $insights->children[1];
        $indications = $dimensions->children[0];
        self::assertTrue($insights->onActivePath);
        self::assertFalse($insights->active);
        self::assertTrue($dimensions->onActivePath);
        self::assertTrue($indications->active);
        self::assertFalse($insights->children[0]->onActivePath, 'The Insights overview is a sibling, not on the path.');
    }

    public function testADetailRouteMarksItsEntry(): void
    {
        $builder = $this->builder([Menu::Statistics->value => $this->statisticsTree()]);

        self::assertSame(['overview', 'top-lists'], $builder->build(Menu::Statistics, 'app_stats_top_lists_show')->activePath);
    }

    public function testRoutesAreNotMatchedByPrefix(): void
    {
        $builder = $this->builder([Menu::Statistics->value => $this->statisticsTree()]);

        self::assertSame([], $builder->build(Menu::Statistics, 'app_stats_top_lists_export')->activePath);
        self::assertSame([], $builder->build(Menu::Statistics, 'app_stats')->activePath);
    }

    public function testAHiddenEntryDoesNotBecomeActive(): void
    {
        $builder = $this->builder([
            Menu::Statistics->value => [
                'groups' => [$this->group('capacity')],
                'items' => [
                    $this->item('assignments', 'capacity', 'Notzuweisungen', route: 'r_assignments'),
                    $this->item('closures', 'capacity', 'Schließungen', route: 'r_closures', granted: 'ROLE_CLOSURE_BETA'),
                ],
            ],
        ], granted: []);

        self::assertNull($builder->build(Menu::Statistics, 'r_closures')->activeId());
    }

    public function testARouteOfASidebarMenuMarksItsEntryInThePrimaryMenu(): void
    {
        $builder = $this->builder([
            Menu::Primary->value => $this->primaryTree(),
            Menu::Statistics->value => $this->statisticsTree(),
        ]);

        self::assertSame(['main', 'statistics'], $builder->build(Menu::Primary, 'app_stats_insights_dimension')->activePath);
    }

    public function testARouteOutsideTheTreeMarksTheAreaOfThePage(): void
    {
        $builder = $this->builder([
            Menu::Primary->value => $this->primaryTree(),
            Menu::Statistics->value => $this->statisticsTree(),
        ]);

        self::assertSame(['main', 'statistics'], $builder->build(Menu::Primary, 'app_other_page', Menu::Statistics)->activePath);
        self::assertSame([], $builder->build(Menu::Primary, 'app_other_page')->activePath, 'Without area, entry or area prefix nothing is active.');
    }

    public function testAnOwnRouteOfThePrimaryMenuWins(): void
    {
        $builder = $this->builder([
            Menu::Primary->value => $this->primaryTree(),
            Menu::Statistics->value => $this->statisticsTree(),
        ]);

        self::assertSame(['main', 'blog'], $builder->build(Menu::Primary, 'app_blog_index', Menu::Statistics)->activePath);
    }

    public function testAnAreaRoutePrefixMarksItsPrimaryEntryWhenNothingMatchesExactly(): void
    {
        $builder = $this->builder([Menu::Primary->value => $this->primaryTree()]);

        self::assertSame(['main', 'statistics'], $builder->build(Menu::Primary, 'app_stats_top_lists_show')->activePath);
        self::assertSame(['main', 'blog'], $builder->build(Menu::Primary, 'app_blog_show')->activePath);
        self::assertSame([], $builder->build(Menu::Primary, 'app_import_index')->activePath, 'No entry claims app_import.');
    }

    public function testTheLongestAreaRoutePrefixWins(): void
    {
        $builder = $this->builder([
            Menu::Primary->value => [
                'groups' => [$this->group('main')],
                'items' => [
                    $this->item('hospitals', 'main', 'Kliniken', route: 'app_hospitals_index', areaRoutePrefix: 'app_hospitals'),
                    $this->item('export', 'main', 'Export', route: 'app_hospitals_export_allocations', areaRoutePrefix: 'app_hospitals_export'),
                ],
            ],
        ]);

        self::assertSame(['main', 'export'], $builder->build(Menu::Primary, 'app_hospitals_export_download')->activePath);
        self::assertSame(['main', 'hospitals'], $builder->build(Menu::Primary, 'app_hospitals_edit')->activePath);
        self::assertSame(['"app_hospitals" (hospitals) contains "app_hospitals_export" (export)'], $builder->overlappingAreaRoutePrefixes(Menu::Primary));
    }

    public function testAreaRoutePrefixesOfHiddenEntriesAreIgnored(): void
    {
        $builder = $this->builder([
            Menu::Primary->value => [
                'groups' => [$this->group('main')],
                'items' => [$this->item('import', 'main', 'Import', route: 'app_import_index', granted: 'ROLE_PARTICIPANT', areaRoutePrefix: 'app_import')],
            ],
        ], granted: []);

        self::assertSame([], $builder->build(Menu::Primary, 'app_import_show')->activePath);
    }

    public function testAnEntryWithAUrlIsALinkButNeverActive(): void
    {
        $builder = $this->builder([
            Menu::Primary->value => [
                'groups' => [$this->group('main')],
                'items' => [new NavItem('page-faq', 'main', new LiteralLabel('Häufige Fragen'), url: '/faq')],
            ],
        ]);

        $menu = $builder->build(Menu::Primary, 'app_page_show');

        self::assertSame(['page-faq'], $this->nodeIds($menu->sections[0]->nodes));
        self::assertNull($menu->activeId());
        self::assertSame('Häufige Fragen', $menu->sections[0]->nodes[0]->item->label->trans($this->createStub(TranslatorInterface::class)));
    }

    public function testDuplicateIdsAreRejected(): void
    {
        $builder = $this->builder([
            Menu::Statistics->value => [
                'groups' => [$this->group('overview')],
                'items' => [$this->item('overview', 'overview', 'Übersicht', route: 'r')],
            ],
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Navigation id "overview" is registered more than once in menu "statistics".');

        $builder->build(Menu::Statistics);
    }

    public function testAnUnknownParentIsRejected(): void
    {
        $builder = $this->builder([
            Menu::Statistics->value => [
                'groups' => [$this->group('overview')],
                'items' => [$this->item('benchmarking', 'comparisons', 'Benchmarking', route: 'r')],
            ],
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Navigation item "benchmarking" in menu "statistics" names the unknown parent "comparisons".');

        $builder->build(Menu::Statistics);
    }

    public function testAParentCycleIsRejected(): void
    {
        $builder = $this->builder([
            Menu::Statistics->value => [
                'groups' => [$this->group('overview')],
                'items' => [
                    $this->item('a', 'b', 'A', route: 'r_a'),
                    $this->item('b', 'a', 'B', route: 'r_b'),
                ],
            ],
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('is part of a parent cycle');

        $builder->build(Menu::Statistics);
    }

    public function testDuplicateRouteClaimsAreReportedRegardlessOfPermissions(): void
    {
        $builder = $this->builder([
            Menu::Statistics->value => [
                'groups' => [$this->group('overview')],
                'items' => [
                    $this->item('reports', 'overview', 'Berichte', route: 'app_stats_reports', detailRoutes: ['app_stats_reports_show']),
                    $this->item('archive', 'overview', 'Archiv', route: 'app_stats_reports_show', granted: 'ROLE_ADMIN'),
                    $this->item('top-lists', 'overview', 'Top-Listen', route: 'app_stats_top_lists'),
                ],
            ],
        ], granted: []);

        self::assertSame(['app_stats_reports_show' => ['reports', 'archive']], $builder->duplicateRouteClaims(Menu::Statistics));
    }

    public function testTheUniqueRouteAssertionPassesForADistinctTree(): void
    {
        $builder = $this->builder([
            Menu::Primary->value => $this->primaryTree(),
            Menu::Statistics->value => $this->statisticsTree(),
        ]);

        self::assertUniqueNavigationRoutes($builder);
    }

    /**
     * @return array{groups: list<NavGroup>, items: list<NavItem>}
     */
    private function statisticsTree(): array
    {
        return [
            'groups' => [$this->group('overview', -100), $this->group('allocations')],
            'items' => [
                $this->item('dashboard', 'overview', 'Übersicht', route: 'app_stats_dashboard', weight: -100),
                $this->item('top-lists', 'overview', 'Top-Listen', route: 'app_stats_top_lists', detailRoutes: ['app_stats_top_lists_show']),
                $this->item('insights', 'allocations', 'Insights'),
                $this->item('insights-overview', 'insights', 'Übersicht', route: 'app_stats_insights', weight: -100),
                $this->item('dimensions', 'insights', 'Dimensionen'),
                $this->item('indications', 'dimensions', 'Indikationen', route: 'app_stats_insights_dimension'),
            ],
        ];
    }

    /**
     * @return array{groups: list<NavGroup>, items: list<NavItem>}
     */
    private function primaryTree(): array
    {
        return [
            'groups' => [$this->group('main')],
            'items' => [
                $this->item('statistics', 'main', 'Statistiken', route: 'app_stats_dashboard', weight: 10, areaRoutePrefix: 'app_stats'),
                $this->item('blog', 'main', 'Blog', route: 'app_blog_index', weight: 20, areaRoutePrefix: 'app_blog'),
            ],
        ];
    }

    private function group(string $id, int $weight = 0): NavGroup
    {
        return new NavGroup($id, new TranslatableMessage($id), $weight);
    }

    /**
     * @param list<string> $detailRoutes
     */
    private function item(
        string $id,
        string $parentId,
        string $label,
        ?string $route = null,
        int $weight = 0,
        ?string $granted = null,
        array $detailRoutes = [],
        ?NavBadge $badge = null,
        ?string $areaRoutePrefix = null,
    ): NavItem {
        return new NavItem($id, $parentId, new TranslatableMessage($label), $route, [], null, $badge, $granted, $detailRoutes, $weight, areaRoutePrefix: $areaRoutePrefix);
    }

    /**
     * @param array<string, array{groups: list<NavGroup>, items: list<NavItem>}> $trees   keyed by menu value
     * @param list<string>|null                                                  $granted null grants everything
     */
    private function builder(array $trees, string $locale = 'en', ?array $granted = null): NavigationBuilder
    {
        $provider = new readonly class($trees) implements NavigationProviderInterface {
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

        $translator = new readonly class($locale) implements TranslatorInterface {
            public function __construct(private string $locale)
            {
            }

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
                return $this->locale;
            }
        };

        return new NavigationBuilder([$provider], $authorizationChecker, $translator);
    }

    /**
     * @return list<string>
     */
    private function sectionIds(NavigationMenu $menu): array
    {
        return array_map(static fn (NavigationSection $section): string => $section->group->id, $menu->sections);
    }

    /**
     * @param list<NavigationNode> $nodes
     *
     * @return list<string>
     */
    private function nodeIds(array $nodes): array
    {
        return array_map(static fn (NavigationNode $node): string => $node->item->id, $nodes);
    }
}
