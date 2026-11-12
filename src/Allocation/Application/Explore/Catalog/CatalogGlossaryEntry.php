<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\Catalog;

/**
 * A glossary hub entry contributed by another context (CatalogGlossaryEntryProviderInterface).
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the glossary index template.
 */
final readonly class CatalogGlossaryEntry
{
    /**
     * @param array<string, int|string> $routeParameters
     */
    public function __construct(
        public string $key,
        public string $titleKey,
        public string $introKey,
        public string $translationDomain,
        public string $icon,
        public string $route,
        public array $routeParameters = [],
    ) {
    }
}
