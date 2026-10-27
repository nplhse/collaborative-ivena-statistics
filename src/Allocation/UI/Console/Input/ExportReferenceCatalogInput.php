<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Input;

use Symfony\Component\Console\Attribute\Option;

final class ExportReferenceCatalogInput
{
    #[Option(description: 'Path of the JSON package to write (default: var/reference/catalog-<timestamp>.json)')]
    public ?string $output = null;

    #[Option(description: 'Comma-separated catalog types to export (default: all)')]
    public ?string $types = null;

    #[Option(description: 'Free-text note stored in the package meta block')]
    public ?string $notes = null;
}
