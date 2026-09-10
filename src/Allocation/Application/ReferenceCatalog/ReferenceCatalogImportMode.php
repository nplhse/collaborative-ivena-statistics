<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog;

enum ReferenceCatalogImportMode: string
{
    case Add = 'add';
    case Replace = 'replace';
}
