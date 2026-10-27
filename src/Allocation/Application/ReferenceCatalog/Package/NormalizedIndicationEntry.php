<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Package;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class NormalizedIndicationEntry implements CatalogEntry
{
    public function __construct(
        #[Assert\Uuid]
        public string $id,
        #[Assert\Regex(pattern: '/^\d{3}$/', message: 'Expected a three-digit code.')]
        public string $code,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $name,
        #[Assert\Length(max: 255)]
        public ?string $note = null,
    ) {
    }
}
