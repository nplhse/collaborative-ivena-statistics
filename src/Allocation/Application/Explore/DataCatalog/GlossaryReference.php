<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\DataCatalog;

/**
 * Glossary page that defines the values of a catalog variable (#1507): its route and parameters and,
 * for a page with several classifications, the section anchor. The glossary page links back to every
 * visible variable that names it.
 */
final readonly class GlossaryReference
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(
        public string $route,
        public array $parameters = [],
        public ?string $section = null,
    ) {
    }

    /**
     * @param array<string, string> $parameters
     */
    public function isFor(string $route, array $parameters): bool
    {
        return $this->route === $route && $this->parameters === $parameters;
    }
}
