<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO\DataCatalog;

final readonly class CatalogGroup
{
    /**
     * @param list<CatalogVariable> $variables
     */
    public function __construct(
        public string $key,
        public array $variables,
    ) {
    }

    public function labelKey(): string
    {
        return 'data_catalog.group.'.$this->key;
    }

    public function anchor(): string
    {
        return 'group-'.str_replace('_', '-', $this->key);
    }
}
