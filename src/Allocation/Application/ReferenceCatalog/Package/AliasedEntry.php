<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Package;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Department or speciality with its aliases.
 */
final readonly class AliasedEntry implements CatalogEntry
{
    /**
     * @param list<AliasEntry> $aliases
     */
    public function __construct(
        #[Assert\Uuid]
        public string $id,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $name,
        #[Assert\Valid]
        public array $aliases = [],
    ) {
    }
}
