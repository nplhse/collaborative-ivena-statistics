<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\Components\Navigation;

use App\Shared\Application\Navigation\Menu\Menu;
use App\Shared\Application\Navigation\Menu\NavigationBuilder;
use App\Shared\Application\Navigation\Menu\NavigationMenu;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The link columns of the site footer from the navigation tree (ADR 035). Columns without links are not
 * shown; footer links are never marked active.
 */
#[AsTwigComponent(name: 'Navigation:Footer', template: '@Shared/components/Navigation/Footer.html.twig')]
final readonly class Footer
{
    public function __construct(
        private NavigationBuilder $navigationBuilder,
    ) {
    }

    public function getMenu(): NavigationMenu
    {
        return $this->navigationBuilder->build(Menu::Footer);
    }
}
