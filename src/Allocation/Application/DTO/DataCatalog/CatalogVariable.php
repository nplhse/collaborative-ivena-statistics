<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO\DataCatalog;

use App\Allocation\Application\Explore\DataCatalog\DataCatalogType;
use App\Allocation\Application\Explore\DataCatalog\GlossaryReference;

final readonly class CatalogVariable
{
    /** Code lists longer than this start collapsed (#1499). */
    public const int VISIBLE_CODES = 5;

    /**
     * @param list<CatalogCode> $codes
     * @param class-string|null $referenceClass target entity of a reference
     * @param string|null       $storageType    SQL column type of the platform, e.g. `varchar(255)`
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
        public ?string $storageType = null,
        public ?GlossaryReference $glossary = null,
    ) {
    }

    public function collapsesCodes(): bool
    {
        return \count($this->codes) > self::VISIBLE_CODES;
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
            $this->storageType,
            $this->glossary,
        );
    }
}
