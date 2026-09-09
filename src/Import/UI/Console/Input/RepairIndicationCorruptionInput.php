<?php

declare(strict_types=1);

namespace App\Import\UI\Console\Input;

use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Validator\Constraints as Assert;

final class RepairIndicationCorruptionInput
{
    #[Option(description: 'Report merges and imports without writing or dispatching', name: 'dry-run')]
    public bool $dryRun = false;

    #[Option(description: 'Only consider quote-broken imports created on/after this date (YYYY-MM-DD). Default: 2025-05-01', name: 'since')]
    public string $since = '2025-05-01';

    #[Option(description: 'Limit discovery and requeue to this import ID', name: 'only-import-id')]
    #[Assert\Positive]
    public ?int $onlyImportId = null;

    #[Option(description: 'Skip IndicationRaw rehash/merge', name: 'skip-merge')]
    public bool $skipMerge = false;

    #[Option(description: 'Skip targeted requeue of quote-broken imports', name: 'skip-requeue')]
    public bool $skipRequeue = false;

    #[Option(description: 'Skip projection rebuild for merge-affected imports', name: 'skip-projection')]
    public bool $skipProjection = false;
}
