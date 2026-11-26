<?php

declare(strict_types=1);

namespace App\Content\UI\Twig\Components;

use App\Shared\Application\Navigation\Menu\Menu;
use App\Shared\Application\Navigation\Menu\NavigationBuilder;
use App\Shared\Application\Navigation\Menu\NavItem;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Reference card of the help (ADR 042): the code-rendered pages of the footer's help column that the viewer may
 * open. CMS pages link by URL and are left out. Renders nothing when no entry is visible.
 */
#[AsTwigComponent(name: 'Content:HelpReference', template: '@Content/components/HelpReference.html.twig')]
final readonly class HelpReference
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private NavigationBuilder $navigationBuilder,
    ) {
    }

    /**
     * @return list<NavItem>
     *
     * @psalm-suppress PossiblyUnusedMethod Called from the component template.
     */
    public function getItems(): array
    {
        foreach ($this->navigationBuilder->build(Menu::Footer)->sections as $section) {
            if (Menu::FOOTER_GROUP_HELP === $section->group->id) {
                return array_values(array_filter(
                    array_map(static fn ($node): NavItem => $node->item, $section->nodes),
                    static fn (NavItem $item): bool => null !== $item->route,
                ));
            }
        }

        return [];
    }
}
