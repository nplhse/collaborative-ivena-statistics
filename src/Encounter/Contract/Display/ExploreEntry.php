<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Display;

use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * A tile of a schema on the Explore overview (ADR 037), in the group of case data. Explore shows it to users
 * who may view cases of at least one hospital; the target page checks the access to each hospital itself.
 * Name the target route `app_explore_…` so that the navigation marks Explore as active.
 */
final readonly class ExploreEntry
{
    /**
     * @param array<string, scalar> $routeParameters
     *
     * @psalm-suppress PossiblyUnusedMethod Contract for encounter schema plugins (ADR 037); plugins and tests call it.
     */
    public function __construct(
        public string $key,
        public TranslatableInterface $title,
        public TranslatableInterface $description,
        public string $icon,
        public string $route,
        public array $routeParameters = [],
        public bool $beta = true,
        public int $weight = 0,
    ) {
    }
}
