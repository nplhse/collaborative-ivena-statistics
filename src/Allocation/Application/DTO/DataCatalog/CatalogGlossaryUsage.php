<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO\DataCatalog;

/**
 * A visible catalog variable whose values a glossary page defines (#1507), for the link from the
 * glossary back to the catalog.
 */
final readonly class CatalogGlossaryUsage
{
    public function __construct(
        public string $objectKey,
        public string $objectDomain,
        public CatalogVariable $variable,
    ) {
    }

    public function objectTitleKey(): string
    {
        return 'data_catalog.object.'.$this->objectKey.'.title';
    }

    public function section(): ?string
    {
        return $this->variable->glossary?->section;
    }
}
