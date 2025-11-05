<?php

declare(strict_types=1);

namespace App\Service\Statistics\Scheduling\Sql;

use App\Service\Statistics\Util\Period;

final class ProviderSql
{
    public static function periodKeySelect(string $gran): string
    {
        return match ($gran) {
            Period::DAY => 'period_day(arrival_at)::date',
            Period::WEEK => 'period_week(arrival_at)::date',
            Period::MONTH => 'period_month(arrival_at)::date',
            Period::QUARTER => 'period_quarter(arrival_at)::date',
            Period::YEAR => 'period_year(arrival_at)::date',
            Period::ALL => "'".Period::ALL_ANCHOR_DATE."'::date",
            default => throw new \RuntimeException("Unknown granularity $gran"),
        };
    }
}
