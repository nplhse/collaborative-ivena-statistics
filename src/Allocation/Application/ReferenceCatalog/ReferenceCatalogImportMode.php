<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog;

/**
 * Import modes of ADR 020: add missing rows, or replace the whole catalog (restore).
 */
enum ReferenceCatalogImportMode: string
{
    case Add = 'add';
    case Replace = 'replace';
}
