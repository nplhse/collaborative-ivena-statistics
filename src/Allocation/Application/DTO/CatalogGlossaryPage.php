<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO;

use App\Allocation\Application\Explore\Catalog\CatalogGlossarySlug;

final readonly class CatalogGlossaryPage
{
    /**
     * @param list<CatalogGlossarySection> $sections
     */
    public function __construct(
        public CatalogGlossarySlug $slug,
        public array $sections,
    ) {
    }

    public function titleKey(): string
    {
        return $this->slug->titleKey();
    }

    public function introKey(): string
    {
        return $this->slug->introKey();
    }

    public function icon(): string
    {
        return $this->slug->icon();
    }
}
