<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog;

/**
 * Import modes of ADR 020. The restore mode (replace) follows with the dependency check.
 */
enum ReferenceCatalogImportMode: string
{
    case Add = 'add';
}
