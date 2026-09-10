<?php

declare(strict_types=1);

namespace App\Import\UI\Console\Input;

use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Validator\Constraints as Assert;

final class ProposeReferenceCatalogFromRejectsInput
{
    #[Option(description: 'Output directory for catalog.yaml, report.md, and requeue files')]
    public string $output = 'var/export/reference-from-rejects';

    #[Option(description: 'Minimum occurrence count for a proposed value', name: 'min-count')]
    #[Assert\Positive]
    public int $minCount = 1;
}
