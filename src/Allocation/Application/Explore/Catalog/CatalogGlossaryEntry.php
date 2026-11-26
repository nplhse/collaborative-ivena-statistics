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
     * @param list<string>              $detailRoutes    routes of further pages of this glossary (for example a diagram);
     *                                                   the explore sidebar marks Classifications on them (ADR 035)
     */
    public function __construct(
        public string $key,
        public string $titleKey,
        public string $introKey,
        public string $translationDomain,
        public string $icon,
        public string $route,
        public array $routeParameters = [],
        public array $detailRoutes = [],
    ) {
    }
}
