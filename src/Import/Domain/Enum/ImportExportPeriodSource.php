<?php

declare(strict_types=1);

namespace App\Import\Domain\Enum;

/**
 * Origin of `import.export_starts_at` / `export_ends_at`.
 * IVENA files carry no export period, so it is derived from the imported rows
 * unless an administrator maintains it manually.
 */
enum ImportExportPeriodSource: string
{
    case DERIVED = 'derived';
    case MANUAL = 'manual';
}
