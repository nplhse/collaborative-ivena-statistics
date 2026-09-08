<?php

declare(strict_types=1);

namespace App\Analytics\UI\Console\Input;

use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Validator\Constraints as Assert;

final class AnalyticsAggregateInput
{
    #[Option(description: 'Aggregate a single calendar day (YYYY-MM-DD, Europe/Berlin).')]
    public ?string $date = null;

    #[Option(description: 'When --date is omitted: number of completed days to aggregate ending yesterday (default: 1).')]
    #[Assert\Range(min: 1, max: 366)]
    public int $days = 1;

    #[Option(description: 'Skip raw-data cleanup after aggregation.', name: 'no-cleanup')]
    public bool $noCleanup = false;

    #[Option(description: 'Only run raw-data cleanup (no aggregation).', name: 'cleanup-only')]
    public bool $cleanupOnly = false;

    #[Option(description: 'Preview cleanup without deleting raw rows.', name: 'dry-run')]
    public bool $dryRun = false;
}
