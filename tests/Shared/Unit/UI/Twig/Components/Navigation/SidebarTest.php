<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\UI\Twig\Components\Navigation;

use App\Shared\Application\Navigation\Menu\LiteralLabel;
use App\Shared\Application\Navigation\Menu\Menu;
use App\Shared\Application\Navigation\Menu\NavGroup;
use App\Shared\Application\Navigation\Menu\NavigationBuilder;
use App\Shared\Application\Navigation\Menu\NavigationProviderInterface;
use App\Shared\Application\Navigation\Menu\NavItem;
use App\Shared\UI\Twig\Components\Navigation\Sidebar;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SidebarTest extends TestCase
{
    public function testTheNavigationIsOnlyAvailableAfterMount(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The sidebar is built on mount.');

        $this->sidebar([])->getNavigation();
    }

    public function testAnEntryWithAUrlLinksToItWithoutCarriedParameters(): void
    {
        $page = new NavItem('page-faq', 'start', new LiteralLabel('FAQ'), url: '/faq');
        $sidebar = $this->sidebar([$page], '/statistics/?scope=public&period=year&year=2025');
        $sidebar->build();

        self::assertSame('/faq', $sidebar->href($page), 'A URL entry is used as it is; scope and period are only added to routes.');
    }

    public function testARouteEntryCarriesTheMenusQueryKeys(): void
    {
        $route = new NavItem('top-lists', 'start', new LiteralLabel('Top lists'), 'app_stats_top_lists');
        $sidebar = $this->sidebar([$route], '/statistics/?scope=public&period=year&year=2025&age_group=0_17');
        $sidebar->build();

        self::assertSame('app_stats_top_lists?scope=public&period=year&year=2025', $sidebar->href($route));
    }

    /**
     * @param list<NavItem> $items
     */
    private function sidebar(array $items, string $uri = '/statistics/'): Sidebar
    {
        $provider = new readonly class($items) implements NavigationProviderInterface {
            /**
             * @param list<NavItem> $items
             */
            public function __construct(private array $items)
            {
            }

            #[\Override]
            public function groups(Menu $menu): array
            {
                return Menu::Statistics === $menu ? [new NavGroup('start')] : [];
            }

            #[\Override]
            public function items(Menu $menu): array
            {
                return Menu::Statistics === $menu ? $this->items : [];
            }
        };

        $authorizationChecker = new readonly class implements AuthorizationCheckerInterface {
            #[\Override]
            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                return true;
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

        $urlGenerator = new readonly class implements UrlGeneratorInterface {
            /**
             * @param array<string, mixed> $parameters
             */
            #[\Override]
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return $name.([] === $parameters ? '' : '?'.http_build_query($parameters));
            }

            #[\Override]
            public function setContext(\Symfony\Component\Routing\RequestContext $context): void
            {
            }

            #[\Override]
            public function getContext(): \Symfony\Component\Routing\RequestContext
            {
                return new \Symfony\Component\Routing\RequestContext();
            }
        };

        $requestStack = new RequestStack([Request::create($uri)]);

        $sidebar = new Sidebar(new NavigationBuilder([$provider], $authorizationChecker, $translator), $requestStack, $urlGenerator);
        $sidebar->menu = Menu::Statistics->value;

        return $sidebar;
    }
}
