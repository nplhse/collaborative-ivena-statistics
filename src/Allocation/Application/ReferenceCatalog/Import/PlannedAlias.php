<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Import;

use App\Allocation\Application\ReferenceCatalog\Package\AliasEntry;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogType;

/**
 * An alias the import creates under a department or speciality.
 */
final readonly class PlannedAlias
{
    public function __construct(
        public ReferenceCatalogType $ownerType,
        public string $ownerId,
        public AliasEntry $alias,
    ) {
    }
}
