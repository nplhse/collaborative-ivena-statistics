<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Package;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class StateEntry implements CatalogEntry
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
