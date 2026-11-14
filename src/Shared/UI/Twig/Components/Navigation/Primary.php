<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\Components\Navigation;

use App\Shared\Application\Navigation\Menu\Menu;
use App\Shared\Application\Navigation\Menu\NavigationBuilder;
use App\Shared\Application\Navigation\Menu\NavigationMenu;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The entries of the top navbar from the navigation tree (ADR 035), as `<li>` elements for the header's
 * `navbar-nav`.
 *
 * `area` names the sidebar menu of the page (`statistics`, `explore`) for routes that have no entry of
 * their own; without it the entry is found by the current route.
 */
#[AsTwigComponent(name: 'Navigation:Primary', template: '@Shared/components/Navigation/Primary.html.twig')]
final class Primary
{
    public ?string $area = null;

    public function __construct(
        private readonly NavigationBuilder $navigationBuilder,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getMenu(): NavigationMenu
    {
        $route = $this->requestStack->getMainRequest()?->attributes->get('_route');

        return $this->navigationBuilder->build(
            Menu::Primary,
            \is_string($route) ? $route : null,
            null === $this->area ? null : Menu::from($this->area),
        );
    }
}
