<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Menu\Inspection;

use App\Shared\Application\Navigation\Menu\Menu;
use App\Shared\Application\Navigation\Menu\NavGroup;
use App\Shared\Application\Navigation\Menu\NavigationBuilder;
use App\Shared\Application\Navigation\Menu\NavigationContributions;
use App\Shared\Application\Navigation\Menu\NavigationOrder;
use App\Shared\Application\Navigation\Menu\NavigationProviderInterface;
use App\Shared\Application\Navigation\Menu\NavigationVisibility;
use App\Shared\Application\Navigation\Menu\NavItem;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The whole navigation tree for the admin backend (#1237, ADR 035): every group and item of every menu
 * with its provider, properties and visibility, and the problems of the tree as warnings.
 *
 * It uses the same merging, visibility and order as {@see NavigationBuilder}, but keeps going where the
 * builder throws. Permissions are evaluated for the current user or with a given check (the admin page
 * passes one for another user). With a route, it
 * also shows the active path the builder would mark in each menu and the rule that chose it.
 */
final readonly class NavigationInspector
{
    private NavigationOrder $order;

    /**
     * @param iterable<NavigationProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(NavigationProviderInterface::TAG)]
        private iterable $providers,
        private NavigationBuilder $builder,
        private AuthorizationCheckerInterface $authorizationChecker,
        private TranslatorInterface $translator,
        private RouterInterface $router,
    ) {
        $this->order = new NavigationOrder($translator);
    }

    /**
     * @param (\Closure(string): bool)|null $isGranted       permission check instead of the current user's
     * @param string|null                   $userIdentifier  the user that `$isGranted` checks, for display
     * @param array<string, string>         $routeParameters
     */
    public function inspect(?\Closure $isGranted = null, ?string $userIdentifier = null, ?string $route = null, array $routeParameters = []): NavigationInspection
    {
        $isGranted ??= fn (string $attribute): bool => $this->authorizationChecker->isGranted($attribute);
        $knownRoutes = $this->router->getRouteCollection()->all();

        $menus = [];
        $claimed = [];
        $areaPrefixes = [];
        foreach (Menu::cases() as $menu) {
            $contributions = NavigationContributions::collect($this->providers, $menu);
            foreach ($contributions->items as $item) {
                foreach ($item->claimedRoutes() as $claimedRoute) {
                    $claimed[$claimedRoute] = true;
                }
                if (Menu::Primary === $menu && null !== $item->areaRoutePrefix) {
                    $areaPrefixes[$item->id] = $item->areaRoutePrefix;
                }
            }

            $menus[] = $this->menu($contributions, $isGranted, $knownRoutes, $route, $routeParameters);
        }

        return new NavigationInspection(
            $menus,
            $this->unclaimedAreaRoutes($areaPrefixes, $claimed, $knownRoutes),
            $userIdentifier,
            $route,
            $routeParameters,
        );
    }

    /**
     * @param \Closure(string): bool                          $isGranted
     * @param array<string, \Symfony\Component\Routing\Route> $knownRoutes
     * @param array<string, string>                           $routeParameters
     */
    private function menu(NavigationContributions $contributions, \Closure $isGranted, array $knownRoutes, ?string $route, array $routeParameters): InspectedMenu
    {
        $activePath = [];
        $activeRule = null;
        $routeCheckError = null;
        if (null !== $route && '' !== $route) {
            try {
                $built = $this->builder->build($contributions->menu, $route, null, $routeParameters, $isGranted);
                $activePath = $built->activePath;
                $activeRule = $built->activeRule;
            } catch (\LogicException $exception) {
                $routeCheckError = $exception->getMessage();
            }
        }

        $visibility = $contributions->visibility($isGranted);
        $children = [];
        foreach ($contributions->items as $item) {
            if (!\in_array($item->id, $contributions->orphans, true)) {
                $children[$item->parentId][] = $item;
            }
        }

        $context = [
            'contributions' => $contributions,
            'visibility' => $visibility,
            'children' => $children,
            'isGranted' => $isGranted,
            'knownRoutes' => $knownRoutes,
            'activeId' => [] === $activePath ? null : $activePath[\count($activePath) - 1],
            'onPath' => array_flip($activePath),
        ];

        $sections = array_map(
            fn (NavGroup $group): InspectedSection => new InspectedSection(
                $group,
                $this->provider($contributions, $group->id),
                InspectedLabel::of($group->label, $this->translator),
                $visibility[$group->id],
                $this->nodes($group->id, 0, $context),
            ),
            $this->order->sort(array_values($contributions->groups)),
        );

        $orphans = array_map(
            fn (string $id): InspectedNode => $this->node($contributions->items[$id], 0, $context, false),
            $contributions->orphans,
        );

        return new InspectedMenu(
            $contributions->menu,
            $sections,
            $orphans,
            $contributions->problems,
            $contributions->duplicateRouteClaims(),
            $contributions->overlappingAreaRoutePrefixes(),
            $activePath,
            $activeRule,
            $routeCheckError,
        );
    }

    /**
     * @param array{contributions: NavigationContributions, visibility: array<string, NavigationVisibility>, children: array<string, list<NavItem>>, isGranted: \Closure(string): bool, knownRoutes: array<string, mixed>, activeId: string|null, onPath: array<string, int>} $context
     *
     * @return list<InspectedNode>
     */
    private function nodes(string $parentId, int $depth, array $context): array
    {
        return array_map(
            fn (NavItem $item): InspectedNode => $this->node($item, $depth, $context, true),
            $this->order->sort($context['children'][$parentId] ?? []),
        );
    }

    /**
     * @param array{contributions: NavigationContributions, visibility: array<string, NavigationVisibility>, children: array<string, list<NavItem>>, isGranted: \Closure(string): bool, knownRoutes: array<string, mixed>, activeId: string|null, onPath: array<string, int>} $context
     */
    private function node(NavItem $item, int $depth, array $context, bool $withChildren): InspectedNode
    {
        $unknownRoutes = array_values(array_filter(
            array_unique($item->claimedRoutes()),
            static fn (string $route): bool => !isset($context['knownRoutes'][$route]),
        ));

        return new InspectedNode(
            $item,
            $this->provider($context['contributions'], $item->id),
            $depth,
            InspectedLabel::of($item->label, $this->translator),
            $context['visibility'][$item->id],
            null === $item->grantedAttribute ? null : $context['isGranted']($item->grantedAttribute),
            $unknownRoutes,
            $item->id === $context['activeId'],
            isset($context['onPath'][$item->id]),
            $withChildren ? $this->nodes($item->id, $depth + 1, $context) : [],
        );
    }

    /**
     * Routes that a page can be opened with (GET), that start with the area prefix of a primary entry and
     * that no entry of any menu claims. Such pages only mark the area in the top navbar.
     *
     * @param array<string, string>                           $areaPrefixes primary entry id => prefix
     * @param array<string, true>                             $claimed      route names claimed in any menu
     * @param array<string, \Symfony\Component\Routing\Route> $knownRoutes
     *
     * @return array<string, array{prefix: string, routes: list<string>}>
     */
    private function unclaimedAreaRoutes(array $areaPrefixes, array $claimed, array $knownRoutes): array
    {
        $unclaimed = [];
        foreach ($areaPrefixes as $entryId => $prefix) {
            $routes = [];
            foreach ($knownRoutes as $name => $definition) {
                $methods = $definition->getMethods();
                if (str_starts_with($name, $prefix) && !isset($claimed[$name]) && ([] === $methods || \in_array('GET', $methods, true))) {
                    $routes[] = $name;
                }
            }
            sort($routes);
            $unclaimed[$entryId] = ['prefix' => $prefix, 'routes' => $routes];
        }

        return $unclaimed;
    }

    /**
     * @return class-string
     */
    private function provider(NavigationContributions $contributions, string $id): string
    {
        return $contributions->providers[$id];
    }
}
