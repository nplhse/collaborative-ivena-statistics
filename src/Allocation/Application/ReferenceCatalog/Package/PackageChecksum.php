<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Package;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class PackageChecksum
{
    public function __construct(
        #[Assert\EqualTo(ReferenceCatalogFormat::CHECKSUM_ALGORITHM)]
        public string $algorithm,
        #[Assert\Regex(pattern: '/^[0-9a-f]{64}$/', message: 'Expected a lowercase SHA-256 hex digest.')]
        public string $value,
    ) {
    }
}
