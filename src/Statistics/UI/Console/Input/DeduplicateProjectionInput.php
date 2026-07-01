<?php

declare(strict_types=1);

namespace App\Statistics\UI\Console\Input;

use Symfony\Component\Console\Attribute\Option;

final class DeduplicateProjectionInput
{
    #[Option(description: 'Only report duplicates without deleting', name: 'dry-run')]
    public bool $dryRun = false;
}
