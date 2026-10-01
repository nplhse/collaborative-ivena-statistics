<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog;

final readonly class ReferenceNameAliasSpec
{
    public function __construct(
        public string $name,
        public string $classification,
        public string $source,
        public ?string $note = null,
        public ?string $validFrom = null,
        public ?string $validTo = null,
    ) {
    }

    /**
     * @return array<string, string|null>
     */
    public function toYaml(): array
    {
        $row = [
            'name' => $this->name,
            'classification' => $this->classification,
            'source' => $this->source,
        ];
        if (null !== $this->note && '' !== $this->note) {
            $row['note'] = $this->note;
        }
        if (null !== $this->validFrom && '' !== $this->validFrom) {
            $row['valid_from'] = $this->validFrom;
        }
        if (null !== $this->validTo && '' !== $this->validTo) {
            $row['valid_to'] = $this->validTo;
        }

        return $row;
    }
}
