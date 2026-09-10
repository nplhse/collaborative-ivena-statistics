<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Input;

use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogImportMode;
use Symfony\Component\Console\Attribute\Option;

final class ImportReferenceCatalogInput
{
    #[Option(description: 'Path to a single catalog YAML file')]
    public string $source = 'fixtures/reference/catalog.yaml';

    #[Option(description: 'add: insert missing rows only. replace: purge catalog tables then load')]
    public ReferenceCatalogImportMode $mode = ReferenceCatalogImportMode::Add;

    #[Option(description: 'Comma-separated catalog types to import')]
    public ?string $types = null;

    #[Option(description: 'Report planned changes without writing', name: 'dry-run')]
    public bool $dryRun = false;

    #[Option(description: 'Username or email for createdBy on new rows')]
    public string $user = 'admin';

    #[Option(description: 'Update existing indication groups (category and membership)')]
    public bool $update = false;
}
