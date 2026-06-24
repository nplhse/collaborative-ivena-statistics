<?php

declare(strict_types=1);

namespace App\Kpi\UI\Console\Input;

use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Validator\Constraints as Assert;

final class KpiAggregateInput
{
    #[Option(description: 'Aggregate a single calendar day (YYYY-MM-DD, Europe/Berlin).')]
    public ?string $date = null;

    #[Option(description: 'When --date is omitted: number of days to aggregate ending yesterday (default: 30, matches dashboard). Use 1 for cron.')]
    #[Assert\Range(min: 1, max: 366)]
    public int $days = 30;
}
