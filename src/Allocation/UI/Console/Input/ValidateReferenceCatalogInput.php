<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Input;

use Symfony\Component\Console\Attribute\Option;

final class ValidateReferenceCatalogInput
{
    #[Option(description: 'Path to the reference catalog package (JSON)')]
    public string $source = 'var/reference/catalog.json';
}
