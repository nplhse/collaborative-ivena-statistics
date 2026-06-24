<?php

declare(strict_types=1);

namespace App\Statistics\UI\Console\Input;

use Symfony\Component\Console\Attribute\Option;

final class DeduplicateProjectionInput
{
    #[Option(description: 'Only report duplicates without deleting (default)', name: 'dry-run')]
    public bool $dryRun = false;

    #[Option(description: 'Delete duplicates, remove orphan projections, and refresh materialized views')]
    public bool $execute = false;
}
