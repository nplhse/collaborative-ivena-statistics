<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO;

final readonly class CatalogGlossaryTerm
{
    /**
     * @param array<string, scalar|null> $filterParams
     */
    public function __construct(
        public string $code,
        public string $labelKey,
        public string $labelDomain,
        public ?string $descriptionKey = null,
        public string $descriptionDomain = 'allocation',
        public ?string $filterRoute = null,
        public array $filterParams = [],
        public string $actionLabelKey = 'catalog.action.view_allocations',
    ) {
    }

    public function hasFilter(): bool
    {
        return null !== $this->filterRoute;
    }
}
