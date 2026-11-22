<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO;

final readonly class CatalogGlossarySection
{
    /**
     * @param list<CatalogGlossaryTerm> $terms
     * @param string|null               $key   anchor of the section, referenced by catalog variables (#1507)
     */
    public function __construct(
        public string $titleKey,
        public array $terms,
        public string $titleDomain = 'allocation',
        public ?string $key = null,
    ) {
    }
}
