<?php

declare(strict_types=1);

namespace App\Shared\UI\Console\Input;

use Symfony\Component\Console\Attribute\Option;

final class BackfillPublicIdsInput
{
    #[Option(description: 'Only report how many rows still need public_id', name: 'dry-run')]
    public bool $dryRun = false;

    /** @var list<string> */
    #[Option(
        description: 'Table(s) to backfill: hospital, secondary_transport, indication_raw, mci_case, allocation, or all',
        name: 'table',
        shortcut: 't',
    )]
    public array $table = ['all'];

    #[Option(description: 'Rows per batch for large tables', name: 'batch-size')]
    public int $batchSize = 5000;

    #[Option(description: 'Stop gracefully after this many seconds (0 = unlimited)', name: 'max-runtime')]
    public int $maxRuntime = 0;
}
