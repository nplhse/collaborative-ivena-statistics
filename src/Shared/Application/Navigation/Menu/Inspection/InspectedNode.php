<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Menu\Inspection;

use App\Shared\Application\Navigation\Menu\NavigationVisibility;
use App\Shared\Application\Navigation\Menu\NavItem;

/**
 * One item of a menu in the admin navigation inspector, visible or not, with its children in menu order.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the admin navigation template.
 */
final readonly class InspectedNode
{
    /**
     * @param class-string        $provider      the provider that contributed the item
     * @param bool|null           $granted       result of the item's own `grantedAttribute`; null without one
     * @param list<string>        $unknownRoutes route and detail route names the router does not know
     * @param list<InspectedNode> $children
     */
    public function __construct(
        public NavItem $item,
        public string $provider,
        public int $depth,
        public ?InspectedLabel $label,
        public NavigationVisibility $visibility,
        public ?bool $granted,
        public array $unknownRoutes,
        public bool $active,
        public bool $onActivePath,
        public array $children,
    ) {
    }
}
