<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Menu\Inspection;

use App\Shared\Application\Navigation\Menu\Menu;
use App\Shared\Application\Navigation\Menu\NavigationActiveRule;

/**
 * One menu in the admin navigation inspector: the whole tree with every group and item, the warnings and,
 * for a checked route, the active path.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the admin navigation template.
 */
final readonly class InspectedMenu
{
    /**
     * @param list<InspectedSection>      $sections                     in menu order
     * @param list<InspectedNode>         $orphans                      items with an unknown parent or in a parent cycle
     * @param list<string>                $problems                     duplicate ids, unknown parents, cycles
     * @param array<string, list<string>> $duplicateRouteClaims
     * @param list<string>                $overlappingAreaRoutePrefixes
     * @param list<string>                $activePath                   for the checked route; empty without match or check
     * @param string|null                 $routeCheckError              why the route could not be checked in this menu
     */
    public function __construct(
        public Menu $menu,
        public array $sections,
        public array $orphans,
        public array $problems,
        public array $duplicateRouteClaims,
        public array $overlappingAreaRoutePrefixes,
        public array $activePath,
        public ?NavigationActiveRule $activeRule,
        public ?string $routeCheckError,
    ) {
    }

    /**
     * @return list<string>
     */
    public function carriedQueryKeys(): array
    {
        return $this->menu->carriedQueryKeys();
    }

    public function primaryEntryId(): ?string
    {
        return $this->menu->primaryEntryId();
    }

    /**
     * Unknown route names of all items, as "item id: route name".
     *
     * @return list<string>
     */
    public function unknownRoutes(): array
    {
        $unknown = [];
        $collect = static function (InspectedNode $node) use (&$collect, &$unknown): void {
            foreach ($node->unknownRoutes as $route) {
                $unknown[] = $node->item->id.': '.$route;
            }
            array_map($collect, $node->children);
        };
        foreach ($this->sections as $section) {
            array_map($collect, $section->nodes);
        }
        array_map($collect, $this->orphans);

        return $unknown;
    }

    public function warningCount(): int
    {
        return \count($this->problems) + \count($this->duplicateRouteClaims) + \count($this->overlappingAreaRoutePrefixes) + \count($this->unknownRoutes());
    }
}
