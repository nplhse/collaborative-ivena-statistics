<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\Components\Navigation;

use App\Shared\Application\Navigation\Menu\Menu;
use App\Shared\Application\Navigation\Menu\NavigationBuilder;
use App\Shared\Application\Navigation\Menu\NavigationMenu;
use App\Shared\Application\Navigation\Menu\NavigationNode;
use App\Shared\Application\Navigation\Menu\NavItem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The contextual sidebar of an area (ADR 035) on Tabler's vertical navbar.
 *
 * Links between views carry the query parameters of {@see Menu::carriedQueryKeys()}; links between the parts
 * of one analysis (children of an entry with `sharedFilterState`) keep the whole query except comparison
 * parameters and the page number.
 */
#[AsTwigComponent(name: 'Navigation:Sidebar', template: '@Shared/components/Navigation/Sidebar.html.twig')]
final class Sidebar
{
    public string $menu;

    private ?NavigationMenu $built = null;

    /** @var array<string, string> item id => parent id */
    private array $parentOf = [];

    /** @var array<string, NavItem> */
    private array $items = [];

    public function __construct(
        private readonly NavigationBuilder $navigationBuilder,
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[PostMount]
    public function build(): void
    {
        $request = $this->request();
        $route = $request?->attributes->get('_route');
        $routeParameters = $request?->attributes->get('_route_params');

        $this->built = $this->navigationBuilder->build(
            Menu::from($this->menu),
            \is_string($route) ? $route : null,
            null,
            \is_array($routeParameters) ? $routeParameters : [],
        );

        foreach ($this->built->sections as $section) {
            $this->index($section->group->id, $section->nodes);
        }
    }

    public function getNavigation(): NavigationMenu
    {
        return $this->built ?? throw new \LogicException('The sidebar is built on mount.');
    }

    /**
     * Label of the area in the top navbar, for the mobile bar.
     *
     * @psalm-suppress PossiblyUnusedMethod Read by the Sidebar template as `this.areaLabel`.
     */
    public function getAreaLabel(): ?TranslatableInterface
    {
        $entryId = Menu::from($this->menu)->primaryEntryId();
        foreach ($this->navigationBuilder->build(Menu::Primary)->sections as $section) {
            foreach ($section->nodes as $node) {
                if ($node->item->id === $entryId) {
                    return $node->item->label;
                }
            }
        }

        return null;
    }

    /**
     * Label of the active entry, for the mobile bar.
     *
     * @psalm-suppress PossiblyUnusedMethod Read by the Sidebar template as `this.activeLabel`.
     */
    public function getActiveLabel(): ?TranslatableInterface
    {
        $activeId = $this->getNavigation()->activeId();

        return null === $activeId ? null : ($this->items[$activeId] ?? null)?->label;
    }

    public function href(NavItem $item): string
    {
        if (null !== $item->url || null === $item->route) {
            return (string) $item->url;
        }

        return $this->urlGenerator->generate($item->route, [...$this->carriedQuery($item), ...$item->routeParameters]);
    }

    /**
     * @param list<NavigationNode> $nodes
     */
    private function index(string $parentId, array $nodes): void
    {
        foreach ($nodes as $node) {
            $this->items[$node->item->id] = $node->item;
            $this->parentOf[$node->item->id] = $parentId;
            $this->index($node->item->id, $node->children);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function carriedQuery(NavItem $target): array
    {
        $query = $this->request()?->query->all() ?? [];
        $activeId = $this->getNavigation()->activeId();

        $analysis = $this->sharedFilterAncestor($target->id);
        if (null !== $analysis && null !== $activeId && $analysis === $this->sharedFilterAncestor($activeId)) {
            return array_filter(
                $query,
                static fn (string $key): bool => 'page' !== $key && !str_starts_with($key, 'comparison_'),
                \ARRAY_FILTER_USE_KEY,
            );
        }

        return array_intersect_key($query, array_flip(Menu::from($this->menu)->carriedQueryKeys()));
    }

    private function sharedFilterAncestor(string $id): ?string
    {
        while (isset($this->parentOf[$id])) {
            $id = $this->parentOf[$id];
            if (($this->items[$id] ?? null)?->sharedFilterState) {
                return $id;
            }
        }

        return null;
    }

    private function request(): ?Request
    {
        return $this->requestStack->getMainRequest();
    }
}
