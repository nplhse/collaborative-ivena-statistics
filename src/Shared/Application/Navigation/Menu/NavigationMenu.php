<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Menu;

/**
 * A menu as the current user sees it, with the active path for the current route.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the navigation components.
 */
final readonly class NavigationMenu
{
    /**
     * @param list<NavigationSection>   $sections
     * @param list<string>              $activePath ids from the group down to the active entry; empty when no entry is active
     * @param NavigationActiveRule|null $activeRule the rule that chose the active entry; null when no entry is active
     */
    public function __construct(
        public Menu $menu,
        public array $sections,
        public array $activePath,
        public ?NavigationActiveRule $activeRule = null,
    ) {
    }

    public function activeId(): ?string
    {
        return [] === $this->activePath ? null : $this->activePath[\count($this->activePath) - 1];
    }
}
