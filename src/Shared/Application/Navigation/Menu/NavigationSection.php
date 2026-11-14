<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Menu;

/**
 * A visible group of a built menu with its top-level nodes, in display order.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the navigation components.
 */
final readonly class NavigationSection
{
    /**
     * @param non-empty-list<NavigationNode> $nodes
     */
    public function __construct(
        public NavGroup $group,
        public array $nodes,
        public bool $onActivePath,
    ) {
    }
}
