<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Application\Navigation\Menu\Inspection;

use App\Shared\Application\Navigation\Menu\Inspection\InspectedMenu;
use App\Shared\Application\Navigation\Menu\Inspection\InspectedNode;
use App\Shared\Application\Navigation\Menu\Inspection\InspectedSection;
use App\Shared\Application\Navigation\Menu\Inspection\NavigationInspection;
use App\Shared\Application\Navigation\Menu\Inspection\NavigationInspector;
use App\Shared\Application\Navigation\Menu\LiteralLabel;
use App\Shared\Application\Navigation\Menu\Menu;
use App\Shared\Application\Navigation\Menu\NavGroup;
use App\Shared\Application\Navigation\Menu\NavigationActiveRule;
use App\Shared\Application\Navigation\Menu\NavigationBuilder;
use App\Shared\Application\Navigation\Menu\NavigationProviderInterface;
use App\Shared\Application\Navigation\Menu\NavigationVisibility;
use App\Shared\Application\Navigation\Menu\NavItem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The admin navigation inspector (#1237) on a small tree: a primary menu with the statistics area and a
 * statistics sidebar from two providers.
 */
final class NavigationInspectorTest extends TestCase
{
    public function testShowsEveryEntryInMenuOrderWithProviderLabelAndVisibility(): void
    {
        [$inspector, $statistics] = $this->inspector(granted: ['ROLE_USER']);

        $menu = $this->menu($inspector->inspect(), Menu::Statistics);

        self::assertSame(['overview', 'capacity'], array_map(static fn (InspectedSection $section): string => $section->group->id, $menu->sections));
        self::assertSame(['dashboard', 'top-lists'], $this->ids($menu->sections[0]->nodes), 'Weight -10 sorts the dashboard first.');

        $closures = $menu->sections[1]->nodes[0];
        self::assertSame('closures', $closures->item->id);
        self::assertSame($statistics::class, $closures->provider);
        self::assertSame(NavigationVisibility::Denied, $closures->visibility);
        self::assertFalse($closures->granted);
        self::assertSame(NavigationVisibility::HiddenParent, $closures->children[0]->visibility, 'Hidden entries stay in the tree.');
        self::assertSame(1, $closures->children[0]->depth);
        self::assertSame(NavigationVisibility::Empty, $menu->sections[1]->visibility);

        $dashboard = $menu->sections[0]->nodes[0];
        self::assertNull($dashboard->granted, 'No own permission');
        self::assertSame('message', $dashboard->label?->source);
        self::assertSame('nav.dashboard', $dashboard->label->key);
        self::assertSame('shared', $dashboard->label->domain);
        self::assertSame('literal', $menu->sections[0]->nodes[1]->label?->source);
        self::assertSame('Top lists', $menu->sections[0]->nodes[1]->label->text);
    }

    public function testAGivenPermissionCheckShowsTheTreeAsAnotherUserSeesIt(): void
    {
        [$inspector] = $this->inspector(granted: ['ROLE_USER']);

        $inspection = $inspector->inspect(static fn (string $attribute): bool => 'ROLE_PARTICIPANT' === $attribute, 'beta-user');

        $closures = $this->menu($inspection, Menu::Statistics)->sections[1]->nodes[0];
        self::assertSame(NavigationVisibility::Visible, $closures->visibility);
        self::assertTrue($closures->granted);
        self::assertSame('beta-user', $inspection->userIdentifier);
    }

    public function testProblemsBecomeWarningsInsteadOfExceptions(): void
    {
        $broken = $this->provider(Menu::Statistics, [], [
            new NavItem('dashboard', 'overview', new TranslatableMessage('again'), 'app_stats_dashboard'),
            new NavItem('lost', 'nowhere', new TranslatableMessage('lost'), 'app_stats_missing'),
        ]);
        [$inspector] = $this->inspector(extra: $broken);

        $inspection = $inspector->inspect(route: 'app_stats_top_lists');
        $menu = $this->menu($inspection, Menu::Statistics);

        self::assertSame([
            'Navigation id "dashboard" is registered more than once in menu "statistics".',
            'Navigation item "lost" in menu "statistics" names the unknown parent "nowhere".',
        ], $menu->problems);
        self::assertSame(['lost'], $this->ids($menu->orphans));
        self::assertSame(NavigationVisibility::Orphaned, $menu->orphans[0]->visibility);
        self::assertSame(['lost: app_stats_missing'], $menu->unknownRoutes(), 'The router does not know app_stats_missing.');
        self::assertSame('Navigation id "dashboard" is registered more than once in menu "statistics".', $menu->routeCheckError);
        self::assertSame(3, $menu->warningCount());
        self::assertSame(3, $inspection->warningCount(), 'The other menus have no problems of their own.');
        self::assertNotNull(
            $this->menu($inspection, Menu::Primary)->routeCheckError,
            'The primary menu has no own entry for the route, looks at the broken statistics sidebar and reports why it cannot.',
        );
    }

    public function testDuplicateRouteClaimsAndOverlappingPrefixesAreListed(): void
    {
        $extra = $this->provider(Menu::Primary, [], [
            new NavItem('stats-copy', Menu::PRIMARY_GROUP, new TranslatableMessage('copy'), 'app_stats_dashboard', areaRoutePrefix: 'app_stats_top'),
        ]);
        [$inspector] = $this->inspector(extra: $extra);

        $primary = $this->menu($inspector->inspect(), Menu::Primary);

        self::assertSame(['app_stats_dashboard' => ['statistics', 'stats-copy']], $primary->duplicateRouteClaims);
        self::assertSame(['"app_stats" (statistics) contains "app_stats_top" (stats-copy)'], $primary->overlappingAreaRoutePrefixes);
    }

    public function testTheRouteCheckShowsThePathAndTheRuleInEveryMenu(): void
    {
        [$inspector] = $this->inspector(granted: ['ROLE_USER']);

        $inspection = $inspector->inspect(route: 'app_stats_top_lists_show', routeParameters: ['list' => 'departments']);

        $statistics = $this->menu($inspection, Menu::Statistics);
        self::assertSame(['overview', 'top-lists'], $statistics->activePath);
        self::assertSame(NavigationActiveRule::DetailRoute, $statistics->activeRule);
        self::assertTrue($statistics->sections[0]->nodes[1]->active);

        $primary = $this->menu($inspection, Menu::Primary);
        self::assertSame([Menu::PRIMARY_GROUP, 'statistics'], $primary->activePath);
        self::assertSame(NavigationActiveRule::SidebarMenu, $primary->activeRule);
        self::assertTrue($primary->sections[0]->nodes[0]->onActivePath);

        self::assertSame([], $this->menu($inspection, Menu::Footer)->activePath);
        self::assertSame(['list' => 'departments'], $inspection->routeParameters);
    }

    public function testListsTheGetRoutesOfAnAreaThatNoEntryClaims(): void
    {
        [$inspector] = $this->inspector();

        self::assertSame(
            ['statistics' => ['prefix' => 'app_stats', 'routes' => ['app_stats_frame', 'app_stats_insights']]],
            $inspector->inspect()->unclaimedAreaRoutes,
            'app_stats_export only accepts POST; dashboard, top lists and closures are claimed.',
        );
    }

    /**
     * @param list<string>|null $granted null grants everything
     *
     * @return array{NavigationInspector, NavigationProviderInterface}
     */
    private function inspector(?array $granted = null, ?NavigationProviderInterface $extra = null): array
    {
        $primary = $this->provider(Menu::Primary, [new NavGroup(Menu::PRIMARY_GROUP)], [
            new NavItem('statistics', Menu::PRIMARY_GROUP, new TranslatableMessage('nav.statistics', [], 'shared'), 'app_stats_dashboard', areaRoutePrefix: 'app_stats'),
        ]);
        $statistics = $this->provider(Menu::Statistics, [new NavGroup('overview', null, -10), new NavGroup('capacity', new TranslatableMessage('capacity'))], [
            new NavItem('top-lists', 'overview', new LiteralLabel('Top lists'), 'app_stats_top_lists', detailRoutes: ['app_stats_top_lists_show']),
            new NavItem('dashboard', 'overview', new TranslatableMessage('nav.dashboard', [], 'shared'), 'app_stats_dashboard', weight: -10),
            new NavItem('closures', 'capacity', new TranslatableMessage('closures'), grantedAttribute: 'ROLE_PARTICIPANT'),
            new NavItem('closures-overview', 'closures', new TranslatableMessage('overview'), 'app_stats_closures'),
        ]);
        $providers = $extra instanceof NavigationProviderInterface ? [$primary, $statistics, $extra] : [$primary, $statistics];

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

        $translator = new readonly class implements TranslatorInterface {
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

        $routes = new RouteCollection();
        foreach (['app_stats_dashboard', 'app_stats_top_lists', 'app_stats_top_lists_show', 'app_stats_closures', 'app_stats_insights', 'app_stats_frame'] as $name) {
            $routes->add($name, new Route('/'.$name));
        }
        $routes->add('app_stats_export', new Route('/export', methods: ['POST']));
        $router = new readonly class($routes) implements RouterInterface {
            public function __construct(private RouteCollection $routes)
            {
            }

            #[\Override]
            public function getRouteCollection(): RouteCollection
            {
                return $this->routes;
            }

            #[\Override]
            public function setContext(RequestContext $context): void
            {
            }

            #[\Override]
            public function getContext(): RequestContext
            {
                return new RequestContext();
            }

            /**
             * @param array<string, mixed> $parameters
             */
            #[\Override]
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return '/'.$name;
            }

            /**
             * @return array<string, mixed>
             */
            #[\Override]
            public function match(string $pathinfo): array
            {
                return [];
            }
        };

        return [
            new NavigationInspector($providers, new NavigationBuilder($providers, $authorizationChecker, $translator), $authorizationChecker, $translator, $router),
            $statistics,
        ];
    }

    private function menu(NavigationInspection $inspection, Menu $menu): InspectedMenu
    {
        foreach ($inspection->menus as $inspected) {
            if ($menu === $inspected->menu) {
                return $inspected;
            }
        }

        self::fail('Menu '.$menu->value.' is missing.');
    }

    /**
     * @param list<InspectedNode> $nodes
     *
     * @return list<string>
     */
    private function ids(array $nodes): array
    {
        return array_map(static fn (InspectedNode $node): string => $node->item->id, $nodes);
    }

    /**
     * @param list<NavGroup> $groups
     * @param list<NavItem>  $items
     */
    private function provider(Menu $for, array $groups, array $items): NavigationProviderInterface
    {
        return new readonly class($for, $groups, $items) implements NavigationProviderInterface {
            /**
             * @param list<NavGroup> $groups
             * @param list<NavItem>  $items
             */
            public function __construct(private Menu $for, private array $groups, private array $items)
            {
            }

            #[\Override]
            public function groups(Menu $menu): array
            {
                return $this->for === $menu ? $this->groups : [];
            }

            #[\Override]
            public function items(Menu $menu): array
            {
                return $this->for === $menu ? $this->items : [];
            }
        };
    }
}
