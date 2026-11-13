<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Tool;

/**
 * One interactive workflow on the Tools landing page (ADR 031), contributed by a provider.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the Tools templates.
 */
final readonly class Tool
{
    /**
     * @param array<string, int|string> $routeParameters
     */
    public function __construct(
        public string $key,
        public string $titleKey,
        public string $descriptionKey,
        public string $translationDomain,
        public string $route,
        public string $icon,
        public ToolCategory $category,
        public int $position = 0,
        public bool $beta = false,
        public array $routeParameters = [],
    ) {
    }
}
