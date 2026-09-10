<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Input;

use Symfony\Component\Console\Attribute\Option;

final class ExportReferenceCatalogInput
{
    #[Option(description: 'Path to the catalog YAML file to write')]
    public string $output = 'fixtures/reference/catalog.yaml';

    #[Option(description: 'Comma-separated catalog types to export')]
    public ?string $types = null;
}
