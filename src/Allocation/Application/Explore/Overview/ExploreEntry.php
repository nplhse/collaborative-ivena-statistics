<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\Overview;

use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * A tile another context adds to a group of the Explore overview: title, description, Tabler icon and the
 * target route. Explore shows it only to users for whom `is_granted($grantedAttribute)` holds (decided without
 * a subject); the target page checks its access itself. Tiles of a group follow the fixed ones by weight.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the Explore overview template.
 */
final readonly class ExploreEntry
{
    /**
     * @param array<string, scalar> $routeParameters
     */
    public function __construct(
        public string $key,
        public ExploreGroup $group,
        public TranslatableInterface $title,
        public TranslatableInterface $description,
        public string $icon,
        public string $route,
        public ?string $grantedAttribute,
        public array $routeParameters = [],
        public bool $beta = false,
        public int $weight = 0,
    ) {
    }
}
