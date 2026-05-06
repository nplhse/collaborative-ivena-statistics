<?php

declare(strict_types=1);

namespace App\Statistics\Application;

/**
 * Shared time window for the statistics overview (charts, hospital summary):
 * from the first day of the month, 12 months back, at 00:00:00 — aligned with
 * {@see \App\Allocation\Infrastructure\Repository\AllocationRepository::countByMonthLast12Months}.
 */
final class StatisticsPeriod
{
    public static function overviewPeriodStart(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('first day of this month')
            ->modify('-11 months')
            ->setTime(0, 0, 0);
    }
}
