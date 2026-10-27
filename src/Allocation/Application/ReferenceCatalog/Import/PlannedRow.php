<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Import;

use App\Allocation\Application\ReferenceCatalog\Package\CatalogEntry;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogType;

/**
 * A package entry the import creates.
 */
final readonly class PlannedRow
{
    public function __construct(
        public ReferenceCatalogType $type,
        public CatalogEntry $entry,
    ) {
    }
}
