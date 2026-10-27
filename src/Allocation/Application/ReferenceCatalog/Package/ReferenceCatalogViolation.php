<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Package;

/**
 * One problem in a package, with the path of the field in the file (for example `hospitals[3].address.postal_code`).
 */
final readonly class ReferenceCatalogViolation
{
    public function __construct(
        public string $path,
        public string $message,
        public ?string $invalidValue = null,
    ) {
    }
}
