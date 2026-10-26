<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Package;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * An external source the package content is based on, for example a PZC list version.
 */
final readonly class PackageSource
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $name,
        #[Assert\Length(max: 64)]
        public ?string $version = null,
        #[Assert\Date]
        public ?string $date = null,
    ) {
    }
}
