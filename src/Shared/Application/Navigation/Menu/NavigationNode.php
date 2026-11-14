<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Menu;

/**
 * A visible item of a built menu with its visible children, in display order.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the navigation components.
 */
final readonly class NavigationNode
{
    /**
     * @param list<NavigationNode> $children
     */
    public function __construct(
        public NavItem $item,
        public array $children,
        public bool $active,
        public bool $onActivePath,
    ) {
    }
}
