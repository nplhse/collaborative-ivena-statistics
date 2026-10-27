<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Input;

use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogImportMode;
use Symfony\Component\Console\Attribute\Option;

final class ImportReferenceCatalogInput
{
    #[Option(description: 'Path to the reference catalog package (JSON)')]
    public string $source = 'var/reference/catalog.json';

    #[Option(description: 'add: insert missing rows, never change existing ones')]
    public ReferenceCatalogImportMode $mode = ReferenceCatalogImportMode::Add;

    #[Option(description: 'Show what the import would do without writing', name: 'dry-run')]
    public bool $dryRun = false;

    #[Option(description: 'Username or email recorded as creator of the new rows')]
    public string $user = 'admin';
}
