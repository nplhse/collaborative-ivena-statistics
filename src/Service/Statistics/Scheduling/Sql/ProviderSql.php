<?php

declare(strict_types=1);

namespace App\Service\Statistics\Scheduling\Sql;

final class ProviderSql
{
    public static function periodKeySelect(string $gran): string
    {
        return match ($gran) {
            'day' => 'period_day(arrival_at)',
            'week' => 'period_week(arrival_at)',
            'month' => 'period_month(arrival_at)',
            'quarter' => 'period_quarter(arrival_at)',
            'year' => 'period_year(arrival_at)',
            default => throw new \InvalidArgumentException('Unsupported granularity '.$gran),
        };
    }
}
