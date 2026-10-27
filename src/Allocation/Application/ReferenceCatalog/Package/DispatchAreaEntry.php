<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Package;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class DispatchAreaEntry implements CatalogEntry
{
    public function __construct(
        #[Assert\Uuid]
        public string $id,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $name,
        /** Public id of the state. */
        #[Assert\Uuid]
        public string $state,
    ) {
    }
}
