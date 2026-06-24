<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Input;

use Symfony\Component\Console\Attribute\Option;

final class BackfillAllocationIndicationNormalizedInput
{
    #[Option(description: 'Only report how many rows would change', name: 'dry-run')]
    public bool $dryRun = false;

    #[Option(description: 'Do not copy indication_raw.target_id to normalized_id', name: 'skip-raw-sync')]
    public bool $skipRawSync = false;

    #[Option(description: 'Do not update allocation indication columns', name: 'skip-allocations')]
    public bool $skipAllocations = false;

    #[Option(description: 'Rebuild allocation_stats_projection per import after backfill', name: 'rebuild-projection')]
    public bool $rebuildProjection = false;
}
