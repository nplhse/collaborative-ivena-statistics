<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO\DataCatalog;

use App\Allocation\Application\Explore\DataCatalog\DataCatalogType;

final readonly class CatalogVariable
{
    /**
     * @param list<CatalogCode> $codes
     * @param class-string|null $referenceClass target entity of a reference
     */
    public function __construct(
        public string $key,
        public string $domain,
        public DataCatalogType $type,
        public bool $nullable,
        public string $table,
        public string $column,
        public array $codes = [],
        public ?ValueRange $range = null,
        public ?string $referenceClass = null,
        public ?string $referenceTable = null,
        public ?string $referenceObjectKey = null,
        public ?string $referenceObjectDomain = null,
    ) {
    }

    public function anchor(): string
    {
        return str_replace(['.', '_'], '-', $this->key);
    }

    public function labelKey(): string
    {
        return 'data_catalog.variable.'.$this->key.'.label';
    }

    public function descriptionKey(): string
    {
        return 'data_catalog.variable.'.$this->key.'.description';
    }

    public function withReferenceObject(?string $objectKey, ?string $objectDomain): self
    {
        return new self(
            $this->key,
            $this->domain,
            $this->type,
            $this->nullable,
            $this->table,
            $this->column,
            $this->codes,
            $this->range,
            $this->referenceClass,
            $this->referenceTable,
            $objectKey,
            $objectDomain,
        );
    }
}
