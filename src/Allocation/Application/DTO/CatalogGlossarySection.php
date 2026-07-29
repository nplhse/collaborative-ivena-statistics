<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO;

final readonly class CatalogGlossarySection
{
    /**
     * @param list<CatalogGlossaryTerm> $terms
     */
    public function __construct(
        public string $titleKey,
        public array $terms,
        public string $titleDomain = 'allocation',
    ) {
    }
}
