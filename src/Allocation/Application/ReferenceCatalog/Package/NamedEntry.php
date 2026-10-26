<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Package;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Assignment, occasion, infection or secondary transport.
 */
final readonly class NamedEntry implements CatalogEntry
{
    public function __construct(
        #[Assert\Uuid]
        public string $id,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $name,
    ) {
    }
}
