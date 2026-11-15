<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Menu\Inspection;

use App\Shared\Application\Navigation\Menu\NavGroup;
use App\Shared\Application\Navigation\Menu\NavigationVisibility;

/**
 * One group of a menu in the admin navigation inspector with all its items in menu order.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the admin navigation template.
 */
final readonly class InspectedSection
{
    /**
     * @param class-string        $provider the provider that contributed the group
     * @param list<InspectedNode> $nodes
     */
    public function __construct(
        public NavGroup $group,
        public string $provider,
        public ?InspectedLabel $label,
        public NavigationVisibility $visibility,
        public array $nodes,
    ) {
    }
}
