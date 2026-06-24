<?php

declare(strict_types=1);

namespace App\Import\UI\Console\Input;

use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Validator\Constraints as Assert;

final class ImportRequeueInput
{
    #[Option(description: 'Show which imports would be dispatched without persisting or dispatching', name: 'dry-run')]
    public bool $dryRun = false;

    #[Option(description: 'Start from this import ID', name: 'from-id')]
    #[Assert\Positive]
    public int $fromId = 1;

    #[Option(description: 'Limit the number of imports to process')]
    #[Assert\Positive]
    public ?int $limit = null;

    #[Option(description: 'Process only this import ID', name: 'only-id')]
    #[Assert\Positive]
    public ?int $onlyId = null;

    #[Option(description: 'Resume the latest incomplete batch run')]
    public bool $resume = false;

    #[Option(description: 'Resume a specific batch run by ID', name: 'run-id')]
    #[Assert\Positive]
    public ?int $runId = null;

    #[Option(description: 'Max dispatch attempts per import before critical exit', name: 'max-retries-per-import')]
    #[Assert\Positive]
    public int $maxRetriesPerImport = 3;
}
